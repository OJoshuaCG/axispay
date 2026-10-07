<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Http\Presenters;

use App\Modules\PaymentLinks\Data\LineItem;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkUrl;
use App\Modules\Shared\Time\IsoDateTime;
use Carbon\CarbonImmutable;

/**
 * The `payment_link` object of the public API (plan 10.5). Amounts as
 * decimal strings plus `amount_minor` (plan 8.3); timestamps ISO-8601 UTC
 * with `Z`; no gateway identifiers (ADR-019). `payment` stays null until
 * payments exist (Phase 4).
 */
final class PaymentLinkPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toApi(PaymentLink $link): array
    {
        $money = $link->money();

        return [
            'id' => $link->prefixedId(),
            'object' => 'payment_link',
            'livemode' => $link->livemode,
            'status' => $link->status->value,
            'url' => PaymentLinkUrl::for($link),
            'amount' => $money->toDecimalString(),
            'amount_minor' => $money->minorAmount,
            'currency' => $money->currency->value,
            'description' => $link->description,
            'metadata' => (object) ($link->metadata ?? []),
            'client_reference_id' => $link->client_reference_id,
            'fx' => [
                'mode' => $link->fx_mode->value,
                'rate' => $link->fx_fixed_rate,
            ],
            'payer_fields' => (object) $link->payer_fields_config,
            'line_items' => self::lineItems($link),
            'return_url' => $link->return_url,
            'auto_redirect' => $link->auto_redirect,
            'locale' => $link->locale,
            'pre_payment_validation' => $link->pre_payment_validation,
            'expires_at' => self::time($link->expires_at),
            'paid_at' => self::time($link->paid_at),
            'canceled_at' => self::time($link->canceled_at),
            'cancel_reason' => $link->cancel_reason,
            'expired_at' => self::time($link->expired_at),
            'refund_status' => $link->refund_status->value,
            'dispute_status' => $link->dispute_status->value,
            'open_count' => $link->open_count,
            'first_opened_at' => self::time($link->first_opened_at),
            'payment' => null,
            'created_at' => self::time($link->created_at),
        ];
    }

    /**
     * @return list<array{label: string, amount: string, absorbs_rounding: bool}>|null
     */
    private static function lineItems(PaymentLink $link): ?array
    {
        $items = $link->lineItems();

        if ($items === []) {
            return null;
        }

        return array_map(
            static fn (LineItem $item): array => ['label' => $item->label, 'amount' => $item->amount->toDecimalString(), 'absorbs_rounding' => $item->absorbsRounding],
            $items,
        );
    }

    private static function time(?CarbonImmutable $time): ?string
    {
        return $time !== null ? IsoDateTime::format($time) : null;
    }
}
