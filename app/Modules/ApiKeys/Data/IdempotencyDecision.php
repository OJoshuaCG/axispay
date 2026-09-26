<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Data;

use App\Modules\ApiKeys\Models\IdempotencyRecord;

/**
 * What to do with an idempotent request: run it (this request owns the key
 * until it finishes) or replay the stored response.
 */
final readonly class IdempotencyDecision
{
    private function __construct(
        public IdempotencyRecord $record,
        public bool $replay,
        public ?string $lockToken,
    ) {}

    /** This request holds the key with `$lockToken` until it completes or releases it. */
    public static function proceed(IdempotencyRecord $record, string $lockToken): self
    {
        return new self($record, false, $lockToken);
    }

    public static function replay(IdempotencyRecord $record): self
    {
        return new self($record, true, null);
    }
}
