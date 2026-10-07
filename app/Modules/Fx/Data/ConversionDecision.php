<?php

declare(strict_types=1);

namespace App\Modules\Fx\Data;

use App\Modules\Fx\Enums\ConversionBlockReason;
use App\Modules\Fx\Enums\FxMode;

/**
 * The answer of ConversionPolicy (plan 13.2): charge the link's own currency
 * (`not_required`), convert with a mode (`convert`), or refuse because the
 * merchant cannot convert (`blocked`).
 */
final readonly class ConversionDecision
{
    public const string NOT_REQUIRED = 'not_required';

    public const string CONVERT = 'convert';

    public const string BLOCKED = 'blocked';

    private function __construct(
        public ?FxMode $mode,
        public ?ConversionBlockReason $blockReason,
    ) {}

    public static function notRequired(): self
    {
        return new self(null, null);
    }

    public static function convert(FxMode $mode): self
    {
        return new self($mode, null);
    }

    public static function blocked(ConversionBlockReason $reason): self
    {
        return new self(null, $reason);
    }

    public function kind(): string
    {
        return match (true) {
            $this->blockReason !== null => self::BLOCKED,
            $this->mode !== null => self::CONVERT,
            default => self::NOT_REQUIRED,
        };
    }

    public function converts(): bool
    {
        return $this->kind() === self::CONVERT;
    }

    public function isBlocked(): bool
    {
        return $this->kind() === self::BLOCKED;
    }
}
