<?php

declare(strict_types=1);

use App\Modules\Access\Providers\AccessServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\PlatformAdmin\Providers\PlatformAdminServiceProvider;
use App\Modules\Shared\Providers\SharedServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    TenancyServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    AccessServiceProvider::class,
    PlatformAdminServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
