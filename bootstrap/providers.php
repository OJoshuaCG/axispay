<?php

declare(strict_types=1);

use App\Modules\Access\Providers\AccessServiceProvider;
use App\Modules\ApiKeys\Providers\ApiKeysServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Branding\Providers\BrandingServiceProvider;
use App\Modules\Checkout\Providers\CheckoutServiceProvider;
use App\Modules\Gateways\Providers\GatewaysServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Legal\Providers\LegalServiceProvider;
use App\Modules\PaymentLinks\Providers\PaymentLinksServiceProvider;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\PlatformAdmin\Providers\PlatformAdminServiceProvider;
use App\Modules\ProviderEvents\Providers\ProviderEventsServiceProvider;
use App\Modules\Shared\Providers\SharedServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Modules\Webhooks\Providers\WebhooksServiceProvider;
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
    BrandingServiceProvider::class,
    LegalServiceProvider::class,
    GatewaysServiceProvider::class,
    ProviderEventsServiceProvider::class,
    ApiKeysServiceProvider::class,
    PaymentLinksServiceProvider::class,
    PaymentsServiceProvider::class,
    CheckoutServiceProvider::class,
    WebhooksServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
