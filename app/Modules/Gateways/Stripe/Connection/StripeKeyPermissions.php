<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe\Connection;

/**
 * The one list of Stripe permissions of the api_key method (ADR-0047):
 * what a merchant's restricted key must have, how ApiKeyFlow probes each
 * one, and the permissions it must NOT have. ApiKeyFlow validates against
 * this class and the panel's help lists it, so the two cannot drift.
 */
final class StripeKeyPermissions
{
    /** Required, proved by reading the account, the pk ↔ rk token check and creating the webhook endpoint. */
    public const array IMPLICIT = ['connected_account_read', 'token_read', 'webhook_write'];

    /** Required, probed without side effects: permission => [HTTP method, path]. */
    public const array REQUIRED_PROBES = [
        'payment_intent_write' => ['post', '/v1/payment_intents'],
        'charge_write' => ['post', '/v1/refunds'],
        'charge_read' => ['get', '/v1/charges?limit=1'],
        'dispute_read' => ['get', '/v1/disputes?limit=1'],
        'event_read' => ['get', '/v1/events?limit=1'],
        'payment_method_read' => ['get', '/v1/payment_methods?limit=1'],
        'confirmation_token_read' => ['get', '/v1/confirmation_tokens/axispay_permission_probe'],
    ];

    /** Never needed, and able to move or expose money: permission => [HTTP method, path]. */
    public const array DANGEROUS_PROBES = [
        'payout_write' => ['post', '/v1/payouts'],
        'transfer_write' => ['post', '/v1/transfers'],
        'balance_read' => ['get', '/v1/balance'],
    ];

    private function __construct() {}

    /**
     * Every permission the restricted key must have, in the order shown to
     * the merchant.
     *
     * @return list<string>
     */
    public static function required(): array
    {
        return [...self::IMPLICIT, ...array_keys(self::REQUIRED_PROBES)];
    }

    /**
     * @return list<string>
     */
    public static function dangerous(): array
    {
        return array_keys(self::DANGEROUS_PROBES);
    }

    /** `read` or `write`, from Stripe's `<resource>_<level>` naming. */
    public static function level(string $permission): string
    {
        return str_ends_with($permission, '_write') ? 'write' : 'read';
    }
}
