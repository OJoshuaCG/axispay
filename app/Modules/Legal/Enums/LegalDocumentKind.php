<?php

declare(strict_types=1);

namespace App\Modules\Legal\Enums;

/**
 * The two legal documents a merchant and the platform publish (ADR-0056).
 * The value is stored and is part of the pay-host URLs
 * (`/l/{token}/legal/{kind}`) and of the `/legal` page anchors.
 */
enum LegalDocumentKind: string
{
    case Privacy = 'privacy';
    case Terms = 'terms';

    /** Translated document name, e.g. "Privacy notice". */
    public function label(): string
    {
        return __('legal.kind.'.$this->value);
    }

    /** The payer-facing short name of the checkout's links ("Terms"). */
    public function checkoutLabel(): string
    {
        return __('checkout.legal.link.'.$this->value);
    }

    /** The payer-facing full name, as a heading ("Terms and conditions"). */
    public function checkoutTitle(): string
    {
        return __('checkout.legal.title.'.$this->value);
    }

    /** Route constraint of the pay-host document pages. */
    public static function pattern(): string
    {
        return implode('|', array_map(static fn (self $kind): string => $kind->value, self::cases()));
    }
}
