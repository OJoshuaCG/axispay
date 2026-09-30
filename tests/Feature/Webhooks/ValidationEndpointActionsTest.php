<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Webhooks\Actions\ConfigureValidationEndpoint;
use App\Modules\Webhooks\Actions\RemoveValidationEndpoint;
use App\Modules\Webhooks\Actions\RotateValidationEndpointSecret;
use App\Modules\Webhooks\Actions\TestValidationEndpoint;
use App\Modules\Webhooks\Data\ValidationEndpointData;
use App\Modules\Webhooks\Enums\UnsafeDestinationReason;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\ValidationResponseProblem;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Notifications\ValidationEndpointNotification;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeHostResolver;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 15.8.1 / 17.3: the actions the panel calls to manage the pre-payment
 * validation URL (`webhooks:manage`, re-authentication, SSRF check, secret,
 * "Test validation").
 */
beforeEach(function (): void {
    FakeHostResolver::install();
    Notification::fake();
});

function validationData(string $url = Validation::URL, bool $enabledByDefault = false, ValidationFailurePolicy $policy = ValidationFailurePolicy::FailClosed): ValidationEndpointData
{
    return new ValidationEndpointData($url, $enabledByDefault, $policy);
}

it('configures the URL, returns its own secret once and stores it encrypted', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $webhookEndpoint = WebhookTestHelpers::endpoint($tenant);

    $issued = app(ConfigureValidationEndpoint::class)->handle($owner, validationData(enabledByDefault: true));

    $raw = Validation::in($tenant, false, static fn () => ValidationEndpoint::query()->toBase()->value('secret'));
    expect($issued->created)->toBeTrue()
        ->and($issued->secret)->toMatch('/^whsec_[A-Za-z0-9+\/]{43}=$/')
        ->and($issued->secret)->not->toBe($webhookEndpoint->secret)
        ->and($issued->endpoint->enabled_by_default)->toBeTrue()
        ->and($issued->endpoint->failure_policy)->toBe(ValidationFailurePolicy::FailClosed)
        ->and($issued->endpoint->livemode)->toBeFalse()
        ->and($raw)->not->toContain('whsec_')
        ->and($issued->endpoint->toArray())->not->toHaveKey('secret')
        ->and(AuditLog::query()->where('action', AuditAction::ValidationEndpointConfigured->value)->count())->toBe(1);
    Notification::assertSentTo($owner, ValidationEndpointNotification::class, static fn (ValidationEndpointNotification $n): bool => $n->change === ValidationEndpointChange::Configured);
});

it('updates the one URL of the mode and keeps its secret', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $first = app(ConfigureValidationEndpoint::class)->handle($owner, validationData());

    $second = app(ConfigureValidationEndpoint::class)->handle($owner, validationData('https://other.merchant.example/v', policy: ValidationFailurePolicy::FailOpen));

    expect($second->created)->toBeFalse()
        ->and($second->secret)->toBeNull()
        ->and($second->endpoint->id)->toBe($first->endpoint->id)
        ->and($second->endpoint->url)->toBe('https://other.merchant.example/v')
        ->and($second->endpoint->failure_policy)->toBe(ValidationFailurePolicy::FailOpen)
        ->and(Validation::freshEndpoint($second->endpoint)->secret)->toBe($first->secret)
        ->and(Validation::in($tenant, false, static fn () => ValidationEndpoint::query()->count()))->toBe(1);
});

it('keeps one URL per mode', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant), livemode: true);
    GatewayTestHelpers::reauthenticated();

    $live = app(ConfigureValidationEndpoint::class)->handle($owner, validationData());

    expect($live->endpoint->livemode)->toBeTrue()
        ->and(Validation::in($tenant, false, static fn () => ValidationEndpoint::query()->count()))->toBe(0);
});

it('requires re-authentication, webhooks:manage and a writable panel', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));

    expect(fn () => app(ConfigureValidationEndpoint::class)->handle($owner, validationData()))->toThrow(ReauthenticationRequiredException::class);

    $viewer = actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    GatewayTestHelpers::reauthenticated();
    expect(fn () => app(ConfigureValidationEndpoint::class)->handle($viewer, validationData()))->toThrow(AuthorizationException::class);

    $suspended = activeTenant(TenantStatus::Suspended);
    $suspendedOwner = actingAsTenantUser(tenantUser($suspended));
    GatewayTestHelpers::reauthenticated();
    $refusal = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(ConfigureValidationEndpoint::class)->handle($suspendedOwner, validationData()));
    expect($refusal->reason)->toBe(WebhookEndpointRefusal::TenantReadOnly);
});

it('refuses a URL the SSRF protection blocks', function (): void {
    $owner = actingAsTenantUser(tenantUser(activeTenant()));
    GatewayTestHelpers::reauthenticated();
    FakeHostResolver::install(['validate.merchant.example' => ['127.0.0.1']]);

    $e = thrownBy(UnsafeDestinationException::class, fn () => app(ConfigureValidationEndpoint::class)->handle($owner, validationData()));

    expect($e->reason)->toBe(UnsafeDestinationReason::ForbiddenAddress);
});

it('rotates the secret and signs with both for 24 hours', function (): void {
    Carbon::setTestNow('2026-10-07 12:00:00');
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $issued = app(ConfigureValidationEndpoint::class)->handle($owner, validationData());

    $rotated = app(RotateValidationEndpointSecret::class)->handle($owner, $issued->endpoint);

    $fresh = Validation::freshEndpoint($issued->endpoint);
    expect($rotated->secret)->not->toBe($issued->secret)
        ->and($fresh->signingSecrets())->toBe([$rotated->secret, $issued->secret])
        ->and($fresh->signingSecrets(now()->addHours(25)->toImmutable()))->toBe([$rotated->secret]);
    Carbon::setTestNow();
});

it('removes the URL after re-authentication, even for a read-only tenant', function (): void {
    $tenant = activeTenant(TenantStatus::Suspended);
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = Validation::endpoint($tenant);

    expect(fn () => app(RemoveValidationEndpoint::class)->handle($owner, $endpoint))->toThrow(ReauthenticationRequiredException::class);

    GatewayTestHelpers::reauthenticated();
    app(RemoveValidationEndpoint::class)->handle($owner, $endpoint);

    expect(Validation::in($tenant, false, static fn () => ValidationEndpoint::query()->count()))->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ValidationEndpointRemoved->value)->count())->toBe(1);
    Notification::assertSentTo($owner, ValidationEndpointNotification::class, static fn (ValidationEndpointNotification $n): bool => $n->change === ValidationEndpointChange::Removed);
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
        ->and(Validation::freshEndpoint($endpoint)->consecutive_failures)->toBe(0)
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
        ->and(Validation::freshEndpoint($endpoint)->consecutive_failures)->toBe(0);
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

it('never lets a user act on another tenant\'s URL (isolation)', function (): void {
    Http::fake();
    $other = activeTenant();
    $foreign = Validation::endpoint($other, false, ['enabled_by_default' => true]);
    $owner = actingAsTenantUser(tenantUser(activeTenant()));
    GatewayTestHelpers::reauthenticated();

    // Not visible in the actor's context (tenant scope: a 404 for the panel).
    expect(ValidationEndpoint::query()->find($foreign->id))->toBeNull();

    expect(fn () => app(RotateValidationEndpointSecret::class)->handle($owner, $foreign))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveValidationEndpoint::class)->handle($owner, $foreign))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TestValidationEndpoint::class)->handle($owner, $foreign))->toThrow(AuthorizationException::class);

    // Configuring creates the actor's own URL; the other tenant's is untouched.
    $mine = app(ConfigureValidationEndpoint::class)->handle($owner, validationData('https://mine.merchant.example/v'));

    expect($mine->endpoint->id)->not->toBe($foreign->id)
        ->and(Validation::freshEndpoint($foreign)->url)->toBe(Validation::URL)
        ->and(Validation::freshEndpoint($foreign)->secret)->toBe($foreign->secret);
    Http::assertNothingSent();
});
