<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\Tenancy\Actions\CreateTenant as CreateTenantAction;
use App\Modules\Tenancy\Data\CreateTenantData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): Model
    {
        $string = static fn (string $key, ?string $default = null): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : $default;

        try {
            return app(CreateTenantAction::class)->handle(PlatformActor::current(), new CreateTenantData(
                legalName: (string) $string('legal_name', ''),
                displayName: (string) $string('display_name', ''),
                ownerEmail: (string) $string('owner_email', ''),
                timezone: (string) $string('timezone', 'America/Mexico_City'),
                defaultLocale: (string) $string('default_locale', 'es'),
                supportEmail: $string('support_email'),
            ));
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
}
