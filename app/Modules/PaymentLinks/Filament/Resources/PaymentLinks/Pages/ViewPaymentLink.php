<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages;

use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Support\Locales;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Link detail: the amount is the heading (numeric font), the description
 * the subheading; "Copy link" and "Cancel link" only while the link can
 * still be paid.
 */
final class ViewPaymentLink extends ViewRecord
{
    protected static string $resource = PaymentLinkResource::class;

    public const int SUBHEADING_LIMIT = 140;

    public function getTitle(): string
    {
        return __('payment_links.page.title');
    }

    public function getHeading(): Htmlable
    {
        return new HtmlString('<span class="amount" lang="'.e(Locales::formattingLanguageTag()).'">'.e(MoneyDisplay::format($this->link()->money())).'</span>');
    }

    public function getSubheading(): string
    {
        return Str::limit($this->link()->description, self::SUBHEADING_LIMIT);
    }

    protected function getHeaderActions(): array
    {
        return [
            PaymentLinkResource::copyLinkAction(),
            PaymentLinkResource::unblockCheckoutAction(),
            PaymentLinkResource::cancelAction(),
        ];
    }

    private function link(): PaymentLink
    {
        $record = $this->getRecord();
        assert($record instanceof PaymentLink);

        return $record;
    }
}
