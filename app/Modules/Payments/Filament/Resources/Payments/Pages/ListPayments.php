<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\Payments\Pages;

use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\Payments\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Payment history of the current mode (ADR-0059): read-only, newest first.
 */
final class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    public function getSubheading(): string
    {
        return __('payments.resource.page.subheading.'.TenantPanel::modeKey());
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
