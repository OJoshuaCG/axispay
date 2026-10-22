<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Jobs\ExpirePaymentLinksJob;
use App\Modules\PaymentLinks\Services\PaymentLinkLookup;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\ValidationTestHelpers as Validation;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * Link lifecycle events (ADR-0067): payment_link.created, .expired and
 * .canceled are recorded in the outbox, in the same transaction as the change.
 *
 * @return list<DomainEvent>
 */
function lifecycleEvents(Tenant|string $tenant, DomainEventType $type): array
{
    return Validation::in($tenant, false, static fn (): array => array_values(
        DomainEvent::query()->where('type', $type->value)->orderBy('id')->get()->all()
    ));
}

it('records payment_link.created once, also when the request is replayed', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $headers = ApiTestHelpers::headers($key, 'idem-lifecycle-created');

    $first = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), $headers)->assertCreated();
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), $headers)->assertCreated();

    $events = lifecycleEvents($tenant, DomainEventType::PaymentLinkCreated);
    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_type)->toBe('payment_link')
        ->and(data_get($events[0]->data, 'payment_link.id'))->toBe($first->json('id'))
        ->and(data_get($events[0]->data, 'payment_link.status'))->toBe('active');
});

it('records payment_link.canceled with the reason when the merchant cancels', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);

    withHeaders(ApiTestHelpers::headers($key))
        ->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ['reason' => 'Order changed'])
        ->assertOk();

    $events = lifecycleEvents($tenant, DomainEventType::PaymentLinkCanceled);
    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_id)->toBe($link->id)
        ->and(data_get($events[0]->data, 'payment_link.status'))->toBe('canceled')
        ->and(data_get($events[0]->data, 'payment_link.cancel_reason'))->toBe('Order changed');
});

it('records nothing when an already canceled link is canceled again', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->canceled());

    withHeaders(ApiTestHelpers::headers($key))
        ->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ['reason' => 'again'])
        ->assertOk();

    expect(lifecycleEvents($tenant, DomainEventType::PaymentLinkCanceled))->toBe([]);
});

it('records payment_link.expired when the job expires a link', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $due = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());
    ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->addDay()]));

    (new ExpirePaymentLinksJob)->handle(app(PaymentLinkLookup::class), app(TenantContext::class), app(ExpirePaymentLink::class));

    $events = lifecycleEvents($tenant, DomainEventType::PaymentLinkExpired);
    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_id)->toBe($due->id)
        ->and(data_get($events[0]->data, 'payment_link.status'))->toBe('expired');
});

it('records payment_link.canceled when a validation rejection cancels the link', function (): void {
    Http::fake(['*' => Validation::answer(['decision' => 'reject', 'cancel_link' => true])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    $events = lifecycleEvents($link->tenant_id, DomainEventType::PaymentLinkCanceled);
    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_id)->toBe($link->id)
        ->and(data_get($events[0]->data, 'payment_link.cancel_reason'))->toBe(CancelReason::RejectedByMerchant->value);
});
