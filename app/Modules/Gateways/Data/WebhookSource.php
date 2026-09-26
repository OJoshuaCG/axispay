<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

use App\Modules\Gateways\Models\GatewayConnection;

/**
 * Which endpoint received a webhook, so the adapter picks the right signing
 * secret (plan 14.1): the platform's Connect endpoint of a mode, or the
 * direct endpoint of one api_key connection. Deviation from the plan 12.1
 * signature, recorded in ADR-0047.
 */
final readonly class WebhookSource
{
    private function __construct(
        public bool $livemode,
        public ?GatewayConnection $connection,
    ) {}

    public static function connect(bool $livemode): self
    {
        return new self($livemode, null);
    }

    public static function direct(GatewayConnection $connection): self
    {
        return new self($connection->livemode, $connection);
    }

    public function isDirect(): bool
    {
        return $this->connection !== null;
    }
}
