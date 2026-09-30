<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages;

use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\Webhooks\Actions\DeleteWebhookEndpoint;
use App\Modules\Webhooks\Actions\DisableWebhookEndpoint;
use App\Modules\Webhooks\Actions\EnableWebhookEndpoint;
use App\Modules\Webhooks\Actions\RevealWebhookEndpointSecret;
use App\Modules\Webhooks\Actions\RotateWebhookEndpointSecret;
use App\Modules\Webhooks\Actions\UpdateWebhookEndpoint;
use App\Modules\Webhooks\Data\IssuedWebhookEndpoint;
use App\Modules\Webhooks\Filament\Concerns\ShowsIssuedSecret;
use App\Modules\Webhooks\Filament\Concerns\ShowsTestResult;
use App\Modules\Webhooks\Filament\Contracts\PresentsTestResults;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Detail of a webhook endpoint (plan 15.1): its settings and health, the
 * delivery log (DeliveriesRelationManager) and the actions: send a test
 * event, edit, disable or enable, rotate or reveal the secret, delete.
 * Editing, enabling, rotating, revealing and deleting ask for the password
 * when the re-authentication window is closed (plan 17.3).
 */
final class ViewWebhookEndpoint extends ViewRecord implements PresentsTestResults
{
    use ShowsIssuedSecret;
    use ShowsTestResult;

    protected static string $resource = WebhookEndpointResource::class;

    public function getTitle(): string
    {
        return __('webhooks.page.view_title');
    }

    public function getHeading(): Htmlable
    {
        return new HtmlString('<span class="break-all">'.e($this->endpoint()->host()).'</span>');
    }

    public function getSubheading(): string
    {
        return __('webhooks.page.view_subheading.'.TenantPanel::modeKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            WebhookEndpointResource::sendTestAction(),
            $this->editAction(),
            $this->enableAction(),
            ActionGroup::make([
                $this->rotateSecretAction(),
                $this->revealSecretAction(),
                $this->disableAction(),
                $this->deleteAction(),
            ])
                ->label(__('webhooks.actions.more'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('webhooks.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->authorize('update')
            ->modalHeading(__('webhooks.actions.edit_heading'))
            ->modalSubmitActionLabel(__('webhooks.actions.save'))
            ->modalWidth('3xl')
            ->fillForm(fn (): array => WebhookEndpointResource::formFill($this->endpoint()))
            ->schema(WebhookEndpointResource::formSchema())
            ->action(function (array $data, Action $action): void {
                WebhookEndpointResource::run(
                    $action,
                    $data,
                    fn (): WebhookEndpoint => app(UpdateWebhookEndpoint::class)->handle(TenantPanel::user(), $this->endpoint(), WebhookEndpointResource::endpointData($data)),
                    fields: WebhookEndpointResource::FIELDS,
                );

                $this->refreshEndpoint();
                Notification::make()->success()->title(__('webhooks.notifications.updated'))->send();
            });
    }

    private function rotateSecretAction(): Action
    {
        return Action::make('rotateSecret')
            ->label(__('webhooks.actions.rotate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.actions.rotate_heading'))
            ->modalDescription(__('webhooks.actions.rotate_help'))
            ->modalSubmitActionLabel(__('webhooks.actions.rotate_submit'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                $issued = WebhookEndpointResource::run(
                    $action,
                    $data,
                    fn (): IssuedWebhookEndpoint => app(RotateWebhookEndpointSecret::class)->handle(TenantPanel::user(), $this->endpoint()),
                );

                $this->refreshEndpoint();
                $this->showIssuedSecret($issued->secret, 'rotated', __('webhooks.notifications.rotated'));
            });
    }

    private function revealSecretAction(): Action
    {
        return Action::make('revealSecret')
            ->label(__('webhooks.actions.reveal'))
            ->icon(Heroicon::OutlinedEye)
            ->authorize('revealSecret')
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.actions.reveal_heading'))
            ->modalDescription(__('webhooks.actions.reveal_help'))
            ->modalSubmitActionLabel(__('webhooks.actions.reveal_submit'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                $secret = WebhookEndpointResource::run(
                    $action,
                    $data,
                    fn (): string => app(RevealWebhookEndpointSecret::class)->handle(TenantPanel::user(), $this->endpoint()),
                );

                $this->showIssuedSecret($secret, 'revealed');
            });
    }

    private function disableAction(): Action
    {
        return Action::make('disable')
            ->label(__('webhooks.actions.disable'))
            ->icon(Heroicon::OutlinedPauseCircle)
            ->authorize('disable')
            ->visible(fn (): bool => $this->endpoint()->isEnabled())
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.actions.disable_heading'))
            ->modalDescription(__('webhooks.actions.disable_help'))
            ->modalSubmitActionLabel(__('webhooks.actions.disable_submit'))
            ->action(function (Action $action): void {
                WebhookEndpointResource::run(
                    $action,
                    [],
                    fn (): WebhookEndpoint => app(DisableWebhookEndpoint::class)->handle(TenantPanel::user(), $this->endpoint()),
                    reauthenticate: false,
                );

                $this->refreshEndpoint();
                Notification::make()->success()->title(__('webhooks.notifications.disabled'))->send();
            });
    }

    private function enableAction(): Action
    {
        return Action::make('enable')
            ->label(__('webhooks.actions.enable'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->authorize('update')
            ->visible(fn (): bool => ! $this->endpoint()->isEnabled())
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.actions.enable_heading'))
            ->modalDescription(__('webhooks.actions.enable_help'))
            ->modalSubmitActionLabel(__('webhooks.actions.enable_submit'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                WebhookEndpointResource::run(
                    $action,
                    $data,
                    fn (): WebhookEndpoint => app(EnableWebhookEndpoint::class)->handle(TenantPanel::user(), $this->endpoint()),
                );

                $this->refreshEndpoint();
                Notification::make()->success()->title(__('webhooks.notifications.enabled'))->send();
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('webhooks.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.actions.delete_heading'))
            ->modalDescription(fn (): string => __('webhooks.actions.delete_help', ['host' => $this->endpoint()->host()]))
            ->modalSubmitActionLabel(__('webhooks.actions.delete_submit'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                WebhookEndpointResource::run(
                    $action,
                    $data,
                    fn () => app(DeleteWebhookEndpoint::class)->handle(TenantPanel::user(), $this->endpoint()),
                );

                Notification::make()->success()->title(__('webhooks.notifications.deleted'))->send();
                $this->redirect(WebhookEndpointResource::getUrl('index'));
            });
    }

    private function endpoint(): WebhookEndpoint
    {
        $record = $this->getRecord();
        assert($record instanceof WebhookEndpoint);

        return $record;
    }

    /** The endpoint changed: read it again so the page shows its new state. */
    private function refreshEndpoint(): void
    {
        $this->endpoint()->refresh();
    }
}
