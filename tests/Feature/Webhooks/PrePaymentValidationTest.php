<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\ValidationFinalDecision;
use App\Modules\Webhooks\Notifications\ValidationEndpointNotification;
use App\Modules\Webhooks\Services\HttpPrePaymentValidator;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakeHostResolver;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 15.8 / ADR-0050 step 4 / ADR-0058: the merchant's signed synchronous
 * callback between the authorization and the capture, its failure policy,
 * `cancel_link`, the failure alert and rules.md rule 7b.
 */
beforeEach(function (): void {
    Notification::fake();
});

it('sends a signed callback with the card brand and country only, and captures on approve', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [, $link, $fake, $endpoint] = Validation::scenario();

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    [$request] = Validation::sentRequests();
    [$call] = Validation::calls($link->tenant_id);
    $body = $request->body();
    $payload = jsonArray($body);

    expect($request->url())->toBe(Validation::URL)
        ->and(WebhookTestHelpers::header($request, 'x-axispay-kind'))->toBe('pre_payment_validation')
        ->and(WebhookTestHelpers::header($request, 'webhook-id'))->toBe($call->prefixedId())
        ->and(app(WebhookSigner::class)->verify(
            $endpoint->secret,
            $call->prefixedId(),
            (int) WebhookTestHelpers::header($request, 'webhook-timestamp'),
            $body,
            WebhookTestHelpers::header($request, 'webhook-signature'),
            Carbon::now()->getTimestamp(),
        ))->toBeTrue()
        ->and($payload['type'])->toBe('payment.pre_validation')
        ->and($payload['id'])->toBe($call->prefixedId())
        ->and($payload['test'])->toBeFalse()
        ->and($payload['attempt_number'])->toBe(1)
        ->and(data_get($payload, 'data.payment_link.id'))->toBe($link->prefixedId())
        ->and(data_get($payload, 'data.card'))->toBe(['brand' => 'visa', 'country' => 'MX'])
        ->and(data_get($payload, 'data.payer'))->toBe(['email' => 'ana@example.com'])
        ->and($body)->not->toContain('4242')
        ->and($body)->not->toContain('fp_fake');

    expect($call->outcome)->toBe(ValidationCallOutcome::Approved)
        ->and($call->final_decision)->toBe(ValidationFinalDecision::Charge)
        ->and($call->response_status)->toBe(200)
        ->and(Checkout::attempts($link)[0]->validation_outcome)->toBe(ValidationOutcome::Approved)
        ->and($fake->callsTo('capturePayment'))->toHaveCount(1);
});

it('voids the authorization on reject and shows the merchant message', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'reject', 'reason_code' => 'out_of_stock', 'payer_message' => "Sin\nstock."])]);
    [, $link, $fake] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected', 'payer_message' => 'Sin stock.']);

    [$call] = Validation::calls($link->tenant_id);
    expect($call->outcome)->toBe(ValidationCallOutcome::Rejected)
        ->and($call->reason_code)->toBe('out_of_stock')
        ->and($call->final_decision)->toBe(ValidationFinalDecision::Block)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toBe([])
        // Without cancel_link the payer may pay again.
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('cancels the link when a rejection asks for it', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'reject', 'cancel_link' => true])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    $fresh = Checkout::freshLink($link);
    expect($fresh->status)->toBe(PaymentLinkStatus::Canceled)
        ->and($fresh->cancel_reason)->toBe(CancelReason::RejectedByMerchant->value)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled);
});

it('cancels the link later when the void\'s outcome was unknown and the gateway reports it released', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'reject', 'cancel_link' => true])]);
    [, $link, $fake] = Validation::scenario();
    $fake->failNext('cancelPayment', new GatewayUnavailableException('down'));

    Checkout::pay($link)->assertJson(['outcome' => 'processing']);

    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture)
        ->and($attempt->validation_cancel_link)->toBeTrue()
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Processing);

    // The void went through after all; Stripe's event arrives later.
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Canceled);
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));

    $fresh = Checkout::freshLink($link);
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fresh->status)->toBe(PaymentLinkStatus::Canceled)
        ->and($fresh->cancel_reason)->toBe(CancelReason::RejectedByMerchant->value);
});

it('cancels the link when a later job retries the failed void', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'reject', 'cancel_link' => true])]);
    [, $link, $fake] = Validation::scenario();
    $fake->failNext('cancelPayment', new GatewayUnavailableException('down'));

    Checkout::pay($link)->assertJson(['outcome' => 'processing']);
    [$attempt] = Checkout::attempts($link);

    // The reconciliation voids it with the kept decision.
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Reconciliation));

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Canceled);
    // The kept decision is reused: the merchant is not asked again.
    Http::assertSentCount(1);
});

it('never cancels the link on approve, even if cancel_link is sent', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve', 'cancel_link' => true])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('voids the authorization on a timeout under fail_closed, without retrying', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received')]);
    [, $link, $fake] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected'])->assertJsonMissingPath('payer_message');

    [$call] = Validation::calls($link->tenant_id);
    expect($call->outcome)->toBe(ValidationCallOutcome::Failed)
        ->and($call->failure_kind)->toBe(ValidationFailureKind::Timeout)
        ->and($call->policy_applied)->toBe(ValidationFailurePolicy::FailClosed)
        ->and($call->final_decision)->toBe(ValidationFinalDecision::Block)
        ->and($call->connection_retried)->toBeFalse()
        ->and(Checkout::attempts($link)[0]->validation_outcome)->toBe(ValidationOutcome::FailedClosed)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toBe([])
        ->and($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
    // The merchant may have processed the request: never sent twice.
    Http::assertSentCount(1);
});

it('captures on a failure under fail_open and says so in the payment', function (): void {
    Http::fake(['*' => Http::response('oops', 500)]);
    [, $link, $fake] = Validation::scenario(['failure_policy' => ValidationFailurePolicy::FailOpen]);

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    [$call] = Validation::calls($link->tenant_id);
    [$attempt] = Checkout::attempts($link);
    expect($call->failure_kind)->toBe(ValidationFailureKind::HttpError)
        ->and($call->policy_applied)->toBe(ValidationFailurePolicy::FailOpen)
        ->and($call->final_decision)->toBe(ValidationFinalDecision::Charge)
        ->and($call->response_status)->toBe(500)
        ->and($attempt->validation_outcome)->toBe(ValidationOutcome::FailedOpen)
        ->and($fake->callsTo('capturePayment'))->toHaveCount(1)
        ->and(PaymentSnapshot::of($attempt, $link->prefixedId())['pre_validation'])->toBe(['outcome' => 'failed', 'policy_applied' => 'fail_open']);
});

it('retries once, immediately, when the connection could not be opened', function (): void {
    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection('cURL error 7: Failed to connect to validate.merchant.example port 443 after 3 ms: Connection refused')
        ->push(['decision' => 'approve'], 200, ['Content-Type' => 'application/json'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    [$call] = Validation::calls($link->tenant_id);
    expect($call->outcome)->toBe(ValidationCallOutcome::Approved)
        ->and($call->connection_retried)->toBeTrue();
    Http::assertSentCount(2);
});

it('retries a connection failure only once, then applies the policy', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect to validate.merchant.example port 443: Connection refused')]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    [$call] = Validation::calls($link->tenant_id);
    expect($call->failure_kind)->toBe(ValidationFailureKind::ConnectionError)
        ->and($call->connection_retried)->toBeTrue();
    Http::assertSentCount(2);
});

it('takes an answer larger than 4 KB as invalid', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve', 'padding' => str_repeat('x', 5000)])]);
    [, $link, $fake] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    [$call] = Validation::calls($link->tenant_id);
    expect($call->failure_kind)->toBe(ValidationFailureKind::InvalidResponse)
        ->and($fake->callsTo('capturePayment'))->toBe([]);
});

it('takes a redirect as a failure and never follows it', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    expect(Validation::calls($link->tenant_id)[0]->failure_kind)->toBe(ValidationFailureKind::HttpError);
    Http::assertSentCount(1);
});

it('takes an unknown decision as invalid', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'maybe'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    expect(Validation::calls($link->tenant_id)[0]->failure_kind)->toBe(ValidationFailureKind::InvalidResponse);
});

it('never calls a destination the SSRF protection blocks', function (): void {
    Http::fake();
    [, $link] = Validation::scenario();
    FakeHostResolver::install(['validate.merchant.example' => ['10.0.0.8']]);

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    expect(Validation::calls($link->tenant_id)[0]->failure_kind)->toBe(ValidationFailureKind::BlockedDestination);
    Http::assertNothingSent();
});

it('counts the DNS lookup against the 5-second budget: a slow lookup is a timeout', function (): void {
    Http::fake();
    [, $link] = Validation::scenario();
    config(['axispay.pre_payment_validation.timeout_seconds' => 1]);
    FakeHostResolver::install()->lookupSeconds = 1.05;

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    expect(Validation::calls($link->tenant_id)[0]->failure_kind)->toBe(ValidationFailureKind::Timeout);
    Http::assertNothingSent();
});

it('does not charge a link that asks for validation when its mode has no endpoint any more', function (): void {
    Http::fake();
    [, $link, $fake, $endpoint] = Validation::scenario(['failure_policy' => ValidationFailurePolicy::FailOpen]);
    Validation::in($endpoint->tenant_id, false, static fn () => $endpoint->delete());

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    [$call] = Validation::calls($link->tenant_id);
    expect($call->failure_kind)->toBe(ValidationFailureKind::EndpointMissing)
        ->and($call->policy_applied)->toBe(ValidationFailurePolicy::FailClosed)
        ->and($fake->callsTo('capturePayment'))->toBe([]);
    Http::assertNothingSent();
});

it('skips the call for a link created without validation', function (): void {
    Http::fake();
    FakeHostResolver::install();
    [$tenant, $link, $fake] = Checkout::scenario();
    Validation::endpoint($tenant);

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    expect(Checkout::attempts($link)[0]->validation_outcome)->toBe(ValidationOutcome::NotConfigured)
        ->and(Validation::calls($tenant))->toBe([])
        ->and($fake->callsTo('capturePayment'))->toHaveCount(1);
    Http::assertNothingSent();
});

it('numbers the validations of a link: a payer who retries is validated again', function (): void {
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Http::fake(['*' => Validation::answer(['decision' => 'reject'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link, 'ctoken_success_1');
    Checkout::pay($link, 'ctoken_success_2');

    expect(array_map(static fn ($call) => $call->attempt_number, Validation::calls($link->tenant_id)))->toBe([1, 2]);
});

it('refuses to be called inside a transaction or under a row lock (rule 7b)', function (): void {
    [, $link] = Validation::scenario();
    $attempt = Validation::authorizedAttempt($link);

    expect(fn () => Checkout::inTenant($link, static fn () => DB::transaction(
        static fn () => app(HttpPrePaymentValidator::class)->decide($link, $attempt),
    )))->toThrow(LogicException::class);
    expect(Validation::calls($link->tenant_id))->toBe([]);
});

it('e-mails the managers after 10 failures in a row, at most once an hour, and resets on success', function (): void {
    Http::fake(['*' => Http::response('down', 503)]);
    [$tenant, $link, , $endpoint] = Validation::scenario();
    $owner = tenantUser($tenant, [SystemRole::Owner]);
    $viewer = tenantUser($tenant, [SystemRole::Viewer]);
    $attempt = Validation::authorizedAttempt($link);
    $validator = app(HttpPrePaymentValidator::class);

    foreach (range(1, 9) as $ignored) {
        Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));
    }

    Notification::assertNothingSent();
    expect(Validation::freshEndpoint($endpoint)->isFailing())->toBeFalse();

    Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));
    Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));

    $fresh = Validation::freshEndpoint($endpoint);
    expect($fresh->consecutive_failures)->toBe(11)
        ->and($fresh->isFailing())->toBeTrue()
        ->and($fresh->last_alerted_at)->not->toBeNull();
    Notification::assertSentToTimes($owner, ValidationEndpointNotification::class, 1);
    Notification::assertSentTo($owner, ValidationEndpointNotification::class, static fn (ValidationEndpointNotification $n): bool => $n->change === ValidationEndpointChange::Failing && $n->host === 'validate.merchant.example');
    Notification::assertNotSentTo($viewer, ValidationEndpointNotification::class);

    // An hour later the next failure e-mails again.
    Carbon::setTestNow(now()->addMinutes(61));
    Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));
    Notification::assertSentToTimes($owner, ValidationEndpointNotification::class, 2);

    // A valid answer resets the count and the alert; validation was never switched off.
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));

    expect(Validation::freshEndpoint($endpoint)->consecutive_failures)->toBe(0)
        ->and(Validation::freshEndpoint($endpoint)->isFailing())->toBeFalse();
    Carbon::setTestNow();
});

it('uses the endpoint of the link\'s own tenant and mode only', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [, $link] = Validation::scenario(['url' => 'https://tenant-a.example/validate']);
    $other = activeTenant();
    Validation::endpoint($other, false, ['url' => 'https://tenant-b.example/validate']);
    // Same tenant, live mode: never used for a test-mode link.
    Validation::endpoint($link->tenant_id, true, ['url' => 'https://live.tenant-a.example/validate']);

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    expect(array_map(static fn ($request) => $request->url(), Validation::sentRequests()))->toBe(['https://tenant-a.example/validate'])
        ->and(Validation::calls($other))->toBe([])
        ->and(Validation::calls($link->tenant_id, livemode: true))->toBe([]);
});

it('keeps the request payload encrypted and out of serialization', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link);

    [$call] = Validation::calls($link->tenant_id);
    $raw = Validation::in($link->tenant_id, false, static fn () => $call->getRawOriginal('request_payload'));

    expect($raw)->not->toContain('ana@example.com')
        ->and($call->toArray())->not->toHaveKey('request_payload')
        ->and(data_get($call->request_payload, 'data.payer.email'))->toBe('ana@example.com');
});
