<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Webhooks\Actions\CreateWebhookEndpoint;
use App\Modules\Webhooks\Actions\DeleteWebhookEndpoint;
use App\Modules\Webhooks\Actions\DisableWebhookEndpoint;
use App\Modules\Webhooks\Actions\TestValidationEndpoint;
use App\Modules\Webhooks\Actions\UpdateWebhookEndpoint;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\ValidationResponseProblem;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeHostResolver;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

/**
 * ADR-0069: the pre-payment validation is an event (`payment.pre_validation`)
 * of the tenant's webhook endpoint. The actions that manage that
 * subscription, its guards (`validation_in_use`, one validator per mode) and
 * the "Test validation" action.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
    Notification::fake();
});

/**
 * @param  list<string>  $events
 */
function validatingEndpointData(array $events = ['payment.pre_validation'], ValidationFailurePolicy $policy = ValidationFailurePolicy::FailClosed, bool $enabledByDefault = false): WebhookEndpointData
{
    return new WebhookEndpointData(Validation::URL, null, $events, $policy, $enabledByDefault);
}

/** An active link that asks for validation, so the endpoint is "in use". */
function openValidatingLink(string $tenantId): PaymentLink
{
    return Validation::in($tenantId, false, static function (): PaymentLink {
        return PaymentLink::factory()->createOne(['pre_payment_validation' => true, 'status' => PaymentLinkStatus::Active]);
    });
}

it('creates the endpoint subscribed to the validation and stores its settings', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $issued = app(CreateWebhookEndpoint::class)->handle($owner, validatingEndpointData(['payment.succeeded', 'payment.pre_validation'], ValidationFailurePolicy::FailOpen, true));

    $endpoint = $issued->endpoint;
    expect($endpoint->validates())->toBeTrue()
        ->and($endpoint->validator_slot)->toBe(WebhookEndpoint::VALIDATOR_SLOT)
        ->and($endpoint->validation_failure_policy)->toBe(ValidationFailurePolicy::FailOpen)
        ->and($endpoint->validation_enabled_by_default)->toBeTrue()
        ->and($issued->secret)->toMatch('/^whsec_[A-Za-z0-9+\/]{43}=$/');
});

it('keeps the wildcard for asynchronous events only: the validation needs its own checkbox', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant, attributes: ['enabled_events' => ['*']]);

    expect($endpoint->validates())->toBeFalse()
        ->and($endpoint->validator_slot)->toBeNull()
        ->and($endpoint->subscribesTo(WebhookEventType::PaymentSucceeded))->toBeTrue()
        ->and($endpoint->subscribesTo(WebhookEventType::PaymentPreValidation))->toBeFalse();

    $both = WebhookTestHelpers::endpoint($tenant, true, ['enabled_events' => ['*', 'payment.pre_validation']]);

    expect($both->validates())->toBeTrue()
        ->and($both->subscribesTo(WebhookEventType::PaymentSucceeded))->toBeTrue();
});

it('lets only one endpoint per mode be the validator', function (): void {
    config(['axispay.webhooks.max_endpoints_per_mode' => 2]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    Validation::endpoint($tenant);

    $refused = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(CreateWebhookEndpoint::class)->handle($owner, validatingEndpointData()));
    expect($refused->reason)->toBe(WebhookEndpointRefusal::ValidatorAlreadyExists);

    // The database refuses it too, whatever the application does.
    expect(fn () => Validation::endpoint($tenant))->toThrow(QueryException::class);

    // The other mode has its own validator.
    expect(Validation::endpoint($tenant, true)->validator_slot)->toBe(WebhookEndpoint::VALIDATOR_SLOT);
});

it('refuses to unsubscribe, disable or delete the validator while open links ask for validation', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = Validation::endpoint($tenant);
    openValidatingLink($tenant->id);

    $unsubscribe = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(UpdateWebhookEndpoint::class)->handle($owner, $endpoint, validatingEndpointData(['payment.succeeded'])));
    $disable = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(DisableWebhookEndpoint::class)->handle($owner, $endpoint));
    $delete = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(DeleteWebhookEndpoint::class)->handle($owner, $endpoint));

    expect($unsubscribe->reason)->toBe(WebhookEndpointRefusal::ValidationInUse)
        ->and($disable->reason)->toBe(WebhookEndpointRefusal::ValidationInUse)
        ->and($delete->reason)->toBe(WebhookEndpointRefusal::ValidationInUse)
        ->and(Validation::freshEndpoint($endpoint)->validates())->toBeTrue();
});

it('lets the validator be changed or removed when no open link needs it', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = Validation::endpoint($tenant);

    $updated = app(UpdateWebhookEndpoint::class)->handle($owner, $endpoint, validatingEndpointData(['payment.succeeded']));

    expect($updated->validates())->toBeFalse()
        ->and($updated->validator_slot)->toBeNull();

    app(DeleteWebhookEndpoint::class)->handle($owner, $updated);
    expect(Validation::in($tenant, false, static fn (): int => WebhookEndpoint::query()->count()))->toBe(0);
});

it('requires webhooks:manage to subscribe an endpoint to the validation', function (): void {
    $tenant = activeTenant();
    $viewer = actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    GatewayTestHelpers::reauthenticated();

    expect(fn () => app(CreateWebhookEndpoint::class)->handle($viewer, validatingEndpointData()))->toThrow(AuthorizationException::class);
});

it('tests the URL with a signed sample and returns what the panel shows', function (): void {
    // A string body: an array body makes the fake force application/json.
    Http::fake(['*' => Http::response(json_encode(['decision' => 'reject', 'reason_code' => 'Bad Code', 'payer_message' => str_repeat('a', 250)], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'text/plain'])]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = Validation::endpoint($tenant);

    $result = app(TestValidationEndpoint::class)->handle($owner, $endpoint);

    [$request] = Validation::sentRequests();
    $payload = jsonArray($request->body());
    expect($payload['test'])->toBeTrue()
        ->and($payload['type'])->toBe('payment.pre_validation')
        ->and(WebhookTestHelpers::header($request, 'x-axispay-kind'))->toBe('pre_payment_validation')
        ->and(WebhookTestHelpers::header($request, 'webhook-id'))->toBe($result->call->prefixedId())
        ->and(app(WebhookSigner::class)->verify($endpoint->secret, $result->call->prefixedId(), (int) WebhookTestHelpers::header($request, 'webhook-timestamp'), $request->body(), WebhookTestHelpers::header($request, 'webhook-signature'), now()->getTimestamp()))->toBeTrue();

    expect($result->httpStatus)->toBe(200)
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($result->decision)->toBe('reject')
        ->and($result->formatValid)->toBeTrue()
        ->and($result->failureKind)->toBeNull()
        ->and($result->errors)->toBe([])
        ->and($result->warnings)->toBe([ValidationResponseProblem::ContentTypeNotJson, ValidationResponseProblem::ReasonCodeInvalid, ValidationResponseProblem::PayerMessageTooLong])
        ->and($result->responseExcerpt)->toContain('"decision":"reject"')
        ->and($result->messages())->toHaveCount(3)
        ->and($result->call->is_test)->toBeTrue()
        ->and($result->call->payment_link_id)->toBeNull()
        // A test never touches the endpoint's health.
        ->and(Validation::freshEndpoint($endpoint)->validation_consecutive_failures)->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ValidationEndpointTestSent->value)->count())->toBe(1);
});

it('reports an invalid answer in the test without counting it as a failure of the endpoint', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json'])]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = Validation::endpoint($tenant);

    $result = app(TestValidationEndpoint::class)->handle($owner, $endpoint);

    expect($result->formatValid)->toBeFalse()
        ->and($result->decision)->toBeNull()
        ->and($result->failureKind)->toBe(ValidationFailureKind::InvalidResponse)
        ->and($result->errors)->toBe([ValidationResponseProblem::DecisionMissing])
        ->and(Validation::freshEndpoint($endpoint)->validation_consecutive_failures)->toBe(0);
});

it('reports a timeout in the test', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received')]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));

    $result = app(TestValidationEndpoint::class)->handle($owner, Validation::endpoint($tenant));

    expect($result->formatValid)->toBeFalse()
        ->and($result->httpStatus)->toBeNull()
        ->and($result->failureKind)->toBe(ValidationFailureKind::Timeout);
});

it('never lets a user act on another tenant\'s validator (isolation)', function (): void {
    Http::fake();
    $other = activeTenant();
    $foreign = Validation::endpoint($other, false, ['validation_enabled_by_default' => true]);
    $owner = actingAsTenantUser(tenantUser(activeTenant()));
    GatewayTestHelpers::reauthenticated();

    // Not visible in the actor's context (tenant scope: a 404 for the panel).
    expect(WebhookEndpoint::query()->find($foreign->id))->toBeNull();

    expect(fn () => app(DeleteWebhookEndpoint::class)->handle($owner, $foreign))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TestValidationEndpoint::class)->handle($owner, $foreign))->toThrow(AuthorizationException::class)
        ->and(Validation::freshEndpoint($foreign)->url)->toBe(Validation::URL);
    Http::assertNothingSent();
});
