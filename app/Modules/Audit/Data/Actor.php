<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

use App\Modules\Audit\Enums\ActorType;

/**
 * Who performed an audited action.
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System);
    }

    public static function user(string $id): self
    {
        return new self(ActorType::User, $id);
    }

    public static function platformAdmin(string $id): self
    {
        return new self(ActorType::PlatformAdmin, $id);
    }
}
