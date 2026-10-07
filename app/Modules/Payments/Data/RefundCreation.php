<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Audit\Data\Actor;
use App\Modules\Payments\Enums\RefundOrigin;

/**
 * Who requests a refund and through which surface (plan 7.5 `origin`,
 * `created_by_actor_*`, `idempotency_key`). `requestHash` is the normalized
 * body fingerprint of the idempotent request.
 */
final readonly class RefundCreation
{
    public function __construct(
        public RefundOrigin $origin,
        public Actor $actor,
        public ?string $idempotencyKey = null,
        public ?string $requestHash = null,
    ) {}
}
