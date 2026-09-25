<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages;

use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Filament\Support\PlatformActor;
use App\Modules\Tenancy\Actions\UpdateTenantProfile;
use App\Modules\Tenancy\Data\UpdateTenantProfileData;
use App\Modules\Tenancy\Models\Tenant;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Profile edit (ADR-0043). The status is not editable here: it changes only
 * through the "Change status" action on the view page (reason, audit,
 * double confirmation to close).
 */
final class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Tenant, 404);

        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        return app(UpdateTenantProfile::class)->handle(PlatformActor::current(), $record, new UpdateTenantProfileData(
            legalName: $string('legal_name'),
            displayName: $string('display_name'),
            timezone: $string('timezone'),
            defaultLocale: $string('default_locale'),
            supportEmail: $string('support_email') !== '' ? $string('support_email') : null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
