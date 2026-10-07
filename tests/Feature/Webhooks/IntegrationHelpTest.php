<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Filament\Pages\PrePaymentValidationSettings;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Modules\Webhooks\Filament\Support\IntegrationHelp;
use App\Modules\Webhooks\Services\PrePaymentValidationClient;
use App\Modules\Webhooks\Services\ValidationPayload;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeHostResolver;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\startSession;

/**
 * "How it works" (IntegrationHelp): a read-only slide-over on the webhook
 * endpoints list and detail and on the pre-payment validation page, for a
 * user with `webhooks:manage`, with the numbers of the configuration and the
 * shortcut from the empty states.
 */
beforeEach(function (): void {
    startSession();
    FakeHostResolver::install();
    Notification::fake();
});

it('explains the webhooks on the endpoints list, with the configured rules', function (string $locale): void {
    $tenant = activeTenant();
    app()->setLocale($locale);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    $component = Livewire::test(ListWebhookEndpoints::class)
        ->assertActionVisible(IntegrationHelp::WEBHOOKS_ACTION)
        ->assertActionHasLabel(IntegrationHelp::WEBHOOKS_ACTION, __('webhooks.help.action'))
        ->mountAction(IntegrationHelp::WEBHOOKS_ACTION)
        ->assertActionMounted(IntegrationHelp::WEBHOOKS_ACTION);

    $component->assertMountedActionModalSee(__('webhooks.help.webhooks.heading'));
    $component->assertMountedActionModalSee('webhook-signature');
    $component->assertMountedActionModalSee(__('webhooks.help.webhooks.delivery.timeout', ['connect' => 5, 'total' => 10]));
    $component->assertMountedActionModalSee(__('webhooks.help.webhooks.delivery.disable', ['days' => 5]));
    $component->assertMountedActionModalSee(__('webhooks.help.webhooks.attempt_immediate', ['number' => 1]));
    $component->assertMountedActionModalSee('standardwebhooks.com');

    foreach (DomainEventType::cases() as $type) {
        $component->assertMountedActionModalSee($type->value);
        $component->assertMountedActionModalSee(WebhookEventType::fromDomain($type)->description());
    }
})->with(['en', 'es']);

it('offers the explanation from the empty list of endpoints', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    Livewire::test(ListWebhookEndpoints::class)
        ->assertSee(__('webhooks.empty.heading'))
        ->assertSee(__('webhooks.help.hint'));
});

it('explains the webhooks on the endpoint detail', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    $component = Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->assertActionVisible(IntegrationHelp::WEBHOOKS_ACTION)
        ->mountAction(IntegrationHelp::WEBHOOKS_ACTION)
        ->assertActionMounted(IntegrationHelp::WEBHOOKS_ACTION);

    $component->assertMountedActionModalSee(__('webhooks.help.webhooks.verify_title'));
});

it('explains the pre-payment validation, not configured and configured', function (string $locale, bool $configured): void {
    $tenant = activeTenant();
    app()->setLocale($locale);

    if ($configured) {
        Validation::endpoint($tenant);
    }

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    $component = Livewire::test(PrePaymentValidationSettings::class)
        ->assertActionVisible(IntegrationHelp::VALIDATION_ACTION)
        ->mountAction(IntegrationHelp::VALIDATION_ACTION)
        ->assertActionMounted(IntegrationHelp::VALIDATION_ACTION);

    $component->assertMountedActionModalSee(__('webhooks.help.validation.heading'));
    $component->assertMountedActionModalSee(PrePaymentValidationClient::KIND_HEADER);
    $component->assertMountedActionModalSee(ValidationPayload::TYPE);
    $component->assertMountedActionModalSee(__('webhooks.help.validation.limits.connect', ['seconds' => config()->integer('axispay.pre_payment_validation.connect_timeout_seconds')]));
    $component->assertMountedActionModalSee(__('webhooks.help.validation.limits.total', ['seconds' => config()->integer('axispay.pre_payment_validation.timeout_seconds')]));
    $component->assertMountedActionModalSee(__('webhooks.help.validation.limits.size', ['kb' => 4]));
    $component->assertMountedActionModalSee(__('webhooks.help.validation.response.payer_message', ['max' => 200]));
    $component->assertMountedActionModalSee(__('webhooks.help.validation.alert', ['count' => 10, 'minutes' => 60]));
})->with(['en', 'es'])->with(['not configured' => false, 'configured' => true]);

it('offers the explanation from the not-configured section', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    Livewire::test(PrePaymentValidationSettings::class)
        ->assertSee(__('webhooks.validation.page.not_configured'))
        ->assertSee(__('webhooks.help.hint'));
});
