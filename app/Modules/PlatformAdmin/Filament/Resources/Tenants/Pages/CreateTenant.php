<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\Identity\Data\IssuedInvitation;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\PlatformAdmin\Filament\Concerns\ShowsInvitationLink;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\Tenancy\Actions\CreateTenant as CreateTenantAction;
use App\Modules\Tenancy\Data\CreateTenantData;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Facades\FilamentView;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creates a tenant and invites its first owner. Before going to the view
 * page, the owner's invitation link is shown once (ADR-0068).
 */
final class CreateTenant extends CreateRecord
{
    use ShowsInvitationLink;

    protected static string $resource = TenantResource::class;

    protected static bool $canCreateAnother = false;

    /** Set while saving; shown once the transaction has committed. This request only. */
    private ?IssuedInvitation $createdOwnerInvitation = null;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): Model
    {
        $string = static fn (string $key, ?string $default = null): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : $default;

        try {
            $created = app(CreateTenantAction::class)->handle(PlatformActor::current(), new CreateTenantData(
                legalName: (string) $string('legal_name', ''),
                displayName: (string) $string('display_name', ''),
                ownerEmail: (string) $string('owner_email', ''),
                timezone: (string) $string('timezone', 'America/Mexico_City'),
                defaultLocale: (string) $string('default_locale', 'es'),
                supportEmail: $string('support_email'),
            ));
            $this->createdOwnerInvitation = $created->ownerInvitation;

            return $created->tenant;
        } catch (ValidationException $e) {
            // The action names its fields; the form's state lives under `data`.
            $messages = [];

            foreach ($e->errors() as $field => $errors) {
                $messages['data.'.$field] = $errors;
            }

            throw ValidationException::withMessages($messages);
        } catch (EmailNotAvailableException) {
            // Registered between the form check and the invitation (race).
            throw ValidationException::withMessages(['data.owner_email' => __('platform.tenants.errors.owner_email_taken')]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * create() redirects to the view page as its last step, after the commit.
     * While the new owner's link has not been shown, the dialog opens here
     * instead, and the redirect happens when it is closed.
     *
     * @param  string  $url
     * @param  bool  $navigate
     */
    public function redirect($url, $navigate = false): void
    {
        if ($this->createdOwnerInvitation !== null) {
            $issued = $this->createdOwnerInvitation;
            $this->createdOwnerInvitation = null;
            $this->showInvitationLink($issued, __('platform.tenants.invitations.notifications.invited'));

            return;
        }

        parent::redirect($url, $navigate);
    }

    protected function afterInvitationLinkClosed(): void
    {
        $url = $this->getRedirectUrl();

        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }
}
