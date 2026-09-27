<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Enums\CancelReason;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Tenancy\Actions\ChangeTenantStatus;
use App\Modules\Tenancy\Data\ChangeTenantStatusData;
use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;

/**
 * Plan 21.3: closing a tenant cancels its active links in both modes with
 * reason `tenant_closed`; a link with a payment under way is left to finish;
 * suspending keeps them (ADR-013).
 */
beforeEach(function (): void {
    Notification::fake();
});

it('cancels the active links of both modes when the tenant is closed', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    GatewayTestHelpers::connection($tenant, true);
    $test = ApiTestHelpers::link($tenant);
    $live = ApiTestHelpers::link($tenant, true);
    $processing = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->processing());
    $paid = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->paid());

    app(ChangeTenantStatus::class)->handle(platformAdmin(), $tenant, new ChangeTenantStatusData(TenantStatus::Closed, 'Contract ended', $tenant->display_name));

    expect(ApiTestHelpers::freshLink($test->id)->status)->toBe(PaymentLinkStatus::Canceled)
        ->and(ApiTestHelpers::freshLink($test->id)->cancel_reason)->toBe(CancelReason::TenantClosed->value)
        ->and(ApiTestHelpers::freshLink($live->id)->status)->toBe(PaymentLinkStatus::Canceled)
        ->and(ApiTestHelpers::freshLink($processing->id)->status)->toBe(PaymentLinkStatus::Processing)
        ->and(ApiTestHelpers::freshLink($paid->id)->status)->toBe(PaymentLinkStatus::Paid);
});

it('keeps the links of a suspended tenant (ADR-013)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);

    app(ChangeTenantStatus::class)->handle(platformAdmin(), $tenant, new ChangeTenantStatusData(TenantStatus::Suspended, 'Unpaid invoice'));

    expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Active);
});
