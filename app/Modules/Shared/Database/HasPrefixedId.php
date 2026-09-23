<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use LogicException;

/**
 * Exposes the model key as a typed public ID (`plink_...`). Use together with
 * HasUlidPrimaryKey on API-visible models.
 */
trait HasPrefixedId
{
    abstract public static function resourceType(): ResourceType;

    public function prefixedId(): string
    {
        $key = $this->getKey();

        if (! is_string($key)) {
            throw new LogicException('A prefixed ID requires a persisted ULID key.');
        }

        return PrefixedId::encode(static::resourceType(), $key);
    }
}
