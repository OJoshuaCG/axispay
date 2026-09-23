<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\Tenancy\Actions\CreateTenant as CreateTenantAction;
use App\Modules\Tenancy\Data\CreateTenantData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $string = static fn (string $key, ?string $default = null): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : $default;

        return app(CreateTenantAction::class)->handle(PlatformActor::current(), new CreateTenantData(
            legalName: (string) $string('legal_name', ''),
            displayName: (string) $string('display_name', ''),
            timezone: (string) $string('timezone', 'America/Mexico_City'),
            defaultLocale: (string) $string('default_locale', 'es'),
            supportEmail: $string('support_email'),
            ownerEmail: $string('owner_email'),
        ));
    }

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
