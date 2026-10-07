<?php

declare(strict_types=1);

use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Webhooks\Services\HttpPrePaymentValidator;
use App\Modules\Webhooks\Services\ValidationPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\ValidationTestHelpers as Validation;

/**
 * The body of the pre-payment validation call names the payment attempt
 * (`pay_…`), so a merchant such as pbx-payments can tell two attempts of the
 * same link apart and recognize a repeated call of one attempt (plan 15.8.3,
 * ADR-0061). The attempt, the link's `client_reference_id`, the link ID and
 * `livemode` are enough to match the call to the merchant's own checkout.
 */
beforeEach(function (): void {
    Notification::fake();
});

it('names the payment attempt, the link, its client reference and the mode in the body', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    [$request] = Validation::sentRequests();
    $payload = jsonArray($request->body());
    $attempt = Checkout::attempts($link)[0];

    expect(data_get($payload, 'data.payment.id'))->toBe($attempt->prefixedId())
        ->and(data_get($payload, 'data.payment.id'))->toStartWith('pay_')
        ->and(data_get($payload, 'data.payment_link.id'))->toBe($link->prefixedId())
        ->and(data_get($payload, 'data.payment_link.client_reference_id'))->toBe($link->client_reference_id)
        ->and($payload['livemode'])->toBeFalse()
        ->and(data_get($payload, 'data.payment'))->toBe(['id' => $attempt->prefixedId()]);
});

it('sends the same attempt ID in the immediate retry after a connection failure', function (): void {
    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection('cURL error 7: Failed to connect to validate.merchant.example port 443 after 3 ms: Connection refused')
        ->push(['decision' => 'approve'], 200, ['Content-Type' => 'application/json'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'paid']);

    [$first, $retry] = Validation::sentRequests();
    $attempt = Checkout::attempts($link)[0];

    expect(data_get(jsonArray($first->body()), 'data.payment.id'))->toBe($attempt->prefixedId())
        ->and(data_get(jsonArray($retry->body()), 'data.payment.id'))->toBe($attempt->prefixedId());
    Http::assertSentCount(2);
});

it('sends the same attempt ID when the same attempt is validated again, and another one for another attempt', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [$tenant, $link] = Validation::scenario();
    // A link has one live attempt at a time: the other attempt belongs to another link of the tenant.
    $otherLink = ApiTestHelpers::link($tenant, false, static fn ($factory) => $factory->state(['pre_payment_validation' => true]));
    $attempt = Validation::authorizedAttempt($link);
    $other = Validation::authorizedAttempt($otherLink);
    $validator = app(HttpPrePaymentValidator::class);

    Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));
    Checkout::inTenant($link, static fn () => $validator->decide($link, $attempt));
    Checkout::inTenant($otherLink, static fn () => $validator->decide($otherLink, $other));

    [$first, $again, $another] = array_map(static fn ($request): array => jsonArray($request->body()), Validation::sentRequests());

    expect(data_get($first, 'data.payment.id'))->toBe($attempt->prefixedId())
        ->and(data_get($again, 'data.payment.id'))->toBe($attempt->prefixedId())
        // Each call is still its own call: only the attempt is stable.
        ->and($again['id'])->not->toBe($first['id'])
        ->and(data_get($another, 'data.payment.id'))->toBe($other->prefixedId())
        ->and($other->prefixedId())->not->toBe($attempt->prefixedId());
});

it('shows a made-up attempt ID in the test call, like the real shape', function (): void {
    $sample = ValidationPayload::sample('val_01', false, CarbonImmutable::now());
    $id = data_get($sample, 'data.payment.id');

    expect($id)->toBeString()
        ->and(PrefixedId::tryParse($id, ResourceType::Payment))->not->toBeNull();
});
