<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\RelationManagers\ValidationCallsRelationManager;
use App\Modules\Webhooks\Models\ValidationCall;
use Livewire\Livewire;
use Tests\Support\FakeHostResolver;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\startSession;

/**
 * ADR-0069: the pre-payment validation lives on the webhook endpoint page: its
 * status, the calls log and the test action with an event choice.
 */
beforeEach(function (): void {
    startSession();
    FakeHostResolver::install();
});

it('shows the validation state and the calls log on the endpoint that validates', function (): void {
    $tenant = activeTenant();
    $endpoint = Validation::endpoint($tenant);
    $call = Validation::in($tenant, false, static function () use ($endpoint): ValidationCall {
        $call = new ValidationCall;
        $call->forceFill(['livemode' => false, 'is_test' => true, 'request_payload' => [], 'webhook_endpoint_id' => $endpoint->id])->save();

        return $call;
    });
    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->assertSee(__('webhooks.fields.validation'))
        ->assertSee(__('webhooks.validation.metrics.no_calls'));

    Livewire::test(ValidationCallsRelationManager::class, ['ownerRecord' => $endpoint, 'pageClass' => ViewWebhookEndpoint::class])
        ->assertCanSeeTableRecords([$call]);
});

it('does not show the validation entries on an endpoint that only sends events', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->assertDontSee(__('webhooks.validation.metrics.no_calls'));
});
