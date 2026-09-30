<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages;

use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\Webhooks\Actions\CreateWebhookEndpoint;
use App\Modules\Webhooks\Data\IssuedWebhookEndpoint;
use App\Modules\Webhooks\Filament\Concerns\ShowsIssuedSecret;
use App\Modules\Webhooks\Filament\Concerns\ShowsTestResult;
use App\Modules\Webhooks\Filament\Contracts\PresentsTestResults;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Webhook endpoints of the current mode, with the create flow (plan 15.1).
 * The new endpoint's signing secret is shown ONCE (ShowsIssuedSecret) and
 * never kept in the page state; afterwards it can only be revealed again
 * with re-authentication, from the endpoint's detail.
 */
final class ListWebhookEndpoints extends ListRecords implements PresentsTestResults
{
    use ShowsIssuedSecret;
    use ShowsTestResult;

    protected static string $resource = WebhookEndpointResource::class;

    public function getSubheading(): string
    {
        return __('webhooks.page.subheading.'.TenantPanel::modeKey(), ['max' => config()->integer('axispay.webhooks.max_endpoints_per_mode')]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('webhooks.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', WebhookEndpoint::class)
                ->modalHeading(__('webhooks.actions.create'))
                ->modalDescription(fn (): string => __('webhooks.actions.create_help.'.TenantPanel::modeKey()))
                ->modalSubmitActionLabel(__('webhooks.actions.create_submit'))
                ->modalWidth('3xl')
                ->schema(WebhookEndpointResource::formSchema())
                ->action(function (array $data, Action $action): void {
                    $issued = WebhookEndpointResource::run(
                        $action,
                        $data,
                        static fn (): IssuedWebhookEndpoint => app(CreateWebhookEndpoint::class)->handle(TenantPanel::user(), WebhookEndpointResource::endpointData($data)),
                        fields: WebhookEndpointResource::FIELDS,
                    );

                    $this->showIssuedSecret($issued->secret, 'created', __('webhooks.notifications.created', ['host' => $issued->endpoint->host()]));
                }),
        ];
    }
}
