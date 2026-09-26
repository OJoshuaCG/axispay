<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use stdClass;

/**
 * A JSON column that always holds an object, even when its keys look like
 * list indexes: `{"0": "a"}` is stored and read back as an object, never as
 * `["a"]` (the `array` cast would turn it into a list).
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, array<array-key, mixed>|null>
 */
final class AsJsonObject implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("The [{$key}] attribute must be an array.");
        }

        return (string) json_encode($value === [] ? new stdClass : (object) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
