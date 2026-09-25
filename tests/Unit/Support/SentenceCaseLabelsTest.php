<?php

declare(strict_types=1);

use App\Modules\Access\Filament\Resources\Roles\RoleResource;
use App\Modules\Identity\Filament\Resources\Users\UserResource;
use App\Modules\PlatformAdmin\Filament\Resources\PlatformAdmins\PlatformAdminResource;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Support\Facades\App;

it('uses sentence case for resource labels in Spanish', function (): void {
    App::setLocale('es');

    expect(TenantResource::getTitleCaseModelLabel())->toBe('cliente')
        ->and(TenantResource::getTitleCasePluralModelLabel())->toBe('Clientes')
        ->and(TenantResource::getNavigationLabel())->toBe('Clientes')
        ->and(PlatformAdminResource::getTitleCasePluralModelLabel())->toBe('Administradores de la plataforma')
        ->and(PlatformAdminResource::getNavigationLabel())->toBe('Administradores')
        ->and(UserResource::getTitleCasePluralModelLabel())->toBe('Usuarios')
        ->and(RoleResource::getTitleCaseModelLabel())->toBe('rol');
});

it('uses sentence case for resource labels in English', function (): void {
    App::setLocale('en');

    expect(TenantResource::getTitleCasePluralModelLabel())->toBe('Tenants')
        ->and(PlatformAdminResource::getTitleCasePluralModelLabel())->toBe('Platform admins')
        ->and(PlatformAdminResource::getNavigationLabel())->toBe('Admins');
});

/**
 * @param  class-string<FilamentResource>  $resource
 */
function assertTitledButNotGloballySearchable(string $resource, string $attribute): void
{
    expect($resource::getRecordTitleAttribute())->toBe($attribute)
        ->and((new ReflectionProperty($resource, 'isGloballySearchable'))->getValue())->toBeFalse();
}

it('does not turn on global search when a record title attribute is set', function (): void {
    assertTitledButNotGloballySearchable(TenantResource::class, 'display_name');
    assertTitledButNotGloballySearchable(UserResource::class, 'name');
    assertTitledButNotGloballySearchable(PlatformAdminResource::class, 'name');
});
