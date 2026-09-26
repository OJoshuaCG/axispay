<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use LogicException;
use Stripe\StripeClient;

/**
 * How to call Stripe for one connection (plan 12.4.1):
 *
 *  - connect:  platform key + `Stripe-Account` header (platform_onboarding, oauth);
 *  - direct:   the merchant's restricted key, no header (api_key);
 *  - platform: platform key, no header (creating accounts and Account Links).
 *
 * Every StripeGateway call builds its request options here, so the header
 * and the idempotency key are never forgotten. A context is built per call:
 * it is never cached, serialized or queued (it may hold a merchant key).
 */
final readonly class StripeCallContext
{
    private function __construct(
        public StripeClient $client,
        public ?string $stripeAccount,
        public ?string $publishableKey,
    ) {}

    public static function connect(StripeClient $client, string $stripeAccount, string $publishableKey): self
    {
        return new self($client, $stripeAccount, $publishableKey);
    }

    public static function direct(StripeClient $client, ?string $publishableKey): self
    {
        return new self($client, null, $publishableKey);
    }

    public static function platform(StripeClient $client): self
    {
        return new self($client, null, null);
    }

    public function isConnect(): bool
    {
        return $this->stripeAccount !== null;
    }

    /**
     * Per-request options: `stripe_account` only in the Connect context, and
     * the idempotency key for every call that creates or modifies (rules.md
     * rule 5). stripe-php resends the same key on its automatic retries.
     *
     * @return array{stripe_account?: string, idempotency_key?: string}
     */
    public function options(?string $idempotencyKey = null): array
    {
        $options = [];

        if ($this->stripeAccount !== null) {
            $options['stripe_account'] = $this->stripeAccount;
        }

        if ($idempotencyKey !== null) {
            $options['idempotency_key'] = $idempotencyKey;
        }

        return $options;
    }

    public function __serialize(): array
    {
        throw new LogicException('A Stripe call context must never be serialized.');
    }
}
