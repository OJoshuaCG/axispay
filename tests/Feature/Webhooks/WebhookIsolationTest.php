<?php

declare(strict_types=1);

use App\Modules\Webhooks\Actions\DisableWebhookEndpoint;
use App\Modules\Webhooks\Actions\ResendWebhookDelivery;
use App\Modules\Webhooks\Actions\SendTestWebhook;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 6.6 / rules.md rule 3: another tenant's endpoints, events and
 * deliveries are never visible (a lookup is a 404, never a 403), and a
 * business event only reaches its own tenant's endpoints.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
    Http::fake(['*' => Http::response('ok')]);
});

it('never shows another tenant\'s endpoints, events or deliveries', function (): void {
    $a = activeTenant();
    $b = activeTenant();
    $endpointA = WebhookTestHelpers::endpoint($a);
    WebhookTestHelpers::record($a, DomainEventType::PaymentLinkOpened);
    [$deliveryA] = WebhookTestHelpers::deliveries($a);
    [$eventA] = WebhookTestHelpers::events($a);

    actingAsTenantUser(tenantUser($b));

    expect(WebhookEndpoint::query()->find($endpointA->id))->toBeNull()
        ->and(WebhookEvent::query()->find($eventA->id))->toBeNull()
        ->and(WebhookDelivery::query()->find($deliveryA->id))->toBeNull()
        ->and(WebhookEndpoint::query()->count())->toBe(0)
        ->and(fn () => WebhookEndpoint::query()->findOrFail($endpointA->id))->toThrow(ModelNotFoundException::class);
});

it('refuses every action on another tenant\'s endpoint or delivery', function (): void {
    $a = activeTenant();
    $b = activeTenant();
    $endpointA = WebhookTestHelpers::endpoint($a);
    WebhookTestHelpers::record($a, DomainEventType::PaymentLinkOpened);
    [$deliveryA] = WebhookTestHelpers::deliveries($a);
    $userB = actingAsTenantUser(tenantUser($b));

    expect(Gate::forUser($userB)->allows('view', $endpointA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('view', $deliveryA))->toBeFalse()
        ->and(fn () => app(DisableWebhookEndpoint::class)->handle($userB, $endpointA))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SendTestWebhook::class)->handle($userB, $endpointA))->toThrow(AuthorizationException::class)
        // The endpoint is looked up in B's context first: not found.
        ->and(fn () => app(ResendWebhookDelivery::class)->handle($userB, $deliveryA))->toThrow(ModelNotFoundException::class);

    expect(WebhookTestHelpers::freshEndpoint($endpointA)->isEnabled())->toBeTrue();
    Http::assertSentCount(1);
});

it('delivers a tenant\'s events only to that tenant\'s endpoints', function (): void {
    $a = activeTenant();
    $b = activeTenant();
    $endpointA = WebhookTestHelpers::endpoint($a);
    WebhookTestHelpers::endpoint($b, attributes: ['url' => 'https://b.merchant.example/hook']);

    WebhookTestHelpers::record($a, DomainEventType::PaymentLinkOpened);

    expect(WebhookTestHelpers::deliveries($b))->toBe([])
        ->and(WebhookTestHelpers::events($b))->toBe([])
        ->and(WebhookTestHelpers::deliveries($a)[0]->webhook_endpoint_id ?? null)->toBe($endpointA->id);
    Http::assertSent(static fn (Request $request): bool => $request->url() === WebhookTestHelpers::URL);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'b.merchant.example'));
});
