<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\Payments\Pages;

use App\Modules\Payments\Filament\Resources\Payments\PaymentResource;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Support\Locales;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * Payment detail (ADR-0059): the amount is the heading (numeric font), the
 * link's description the subheading; the card, the validation outcome and
 * the timeline below. Read-only.
 */
final class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public const int SUBHEADING_LIMIT = 140;

    public function getTitle(): string
    {
        return __('payments.resource.page.view_title');
    }

    public function getHeading(): Htmlable
    {
        return PaymentResource::amountHeading($this->payment(), Locales::formattingLanguageTag());
    }

    public function getSubheading(): string
    {
        return Str::limit($this->payment()->link->description ?? '', self::SUBHEADING_LIMIT);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function payment(): PaymentAttempt
    {
        $record = $this->getRecord();
        assert($record instanceof PaymentAttempt);

        return $record;
    }
}
