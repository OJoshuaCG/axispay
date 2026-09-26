<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Data;

use App\Modules\Audit\Data\Actor;
use App\Modules\PaymentLinks\Enums\CreatedVia;

/**
 * Who creates a link and through which surface (plan 7.5 `created_via`,
 * `created_by_actor_*`, `idempotency_key`). `requestHash` is the normalized
 * body fingerprint of the idempotent request that creates the link.
 */
final readonly class CreationContext
{
    public function __construct(
        public CreatedVia $via,
        public Actor $actor,
        public ?string $idempotencyKey = null,
        public ?string $requestHash = null,
    ) {}
}
