<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Data\ConnectedAccountData;
use Stripe\Account;
use Stripe\StripeObject;

/**
 * Stripe Account object -> provider-neutral ConnectedAccountData. Only the
 * fields the connection needs are kept; identity data never leaves here.
 */
final class StripeAccountMapper
{
    public static function toData(Account $account): ConnectedAccountData
    {
        $requirements = $account->requirements ?? null;

        return new ConnectedAccountData(
            providerAccountId: $account->id,
            country: self::upperOrNull($account->country ?? null),
            defaultCurrency: self::lowerOrNull($account->default_currency ?? null),
            chargesEnabled: ($account->charges_enabled ?? false) === true,
            payoutsEnabled: ($account->payouts_enabled ?? false) === true,
            detailsSubmitted: ($account->details_submitted ?? false) === true,
            requirements: $requirements instanceof StripeObject ? self::requirements($requirements) : ConnectedAccountData::emptyRequirements(),
        );
    }

    /**
     * @return array{currently_due: list<string>, eventually_due: list<string>, past_due: list<string>, pending_verification: list<string>, disabled_reason: string|null, current_deadline: int|null}
     */
    private static function requirements(StripeObject $requirements): array
    {
        $values = $requirements->toArray();
        $reason = $values['disabled_reason'] ?? null;
        $deadline = $values['current_deadline'] ?? null;

        return [
            'currently_due' => self::strings($values['currently_due'] ?? []),
            'eventually_due' => self::strings($values['eventually_due'] ?? []),
            'past_due' => self::strings($values['past_due'] ?? []),
            'pending_verification' => self::strings($values['pending_verification'] ?? []),
            'disabled_reason' => is_string($reason) ? $reason : null,
            'current_deadline' => is_int($deadline) ? $deadline : null,
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    private static function upperOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? strtoupper($value) : null;
    }

    private static function lowerOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }
}
