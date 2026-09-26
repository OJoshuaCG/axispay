<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

use stdClass;

/**
 * SHA-256 of a request body in a normalized form (plan 7.8
 * `request_hash`), so two bodies that mean the same are the same request:
 *
 *  - an empty body, `{}` and `[]` all mean "no fields";
 *  - JSON objects are written with sorted keys and stay objects
 *    (`{"0":"a"}` is not `["a"]`); whitespace does not count;
 *  - a number and a string are different values;
 *  - a body that is not valid JSON is hashed as sent.
 */
final class RequestFingerprint
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function of(string $rawBody): string
    {
        if (trim($rawBody) === '') {
            return hash('sha256', '{}');
        }

        $decoded = json_decode($rawBody, false, 64);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return hash('sha256', $rawBody);
        }

        return hash('sha256', $decoded === [] ? '{}' : $this->canonical($decoded));
    }

    private function canonical(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $entries = [];

            foreach (get_object_vars($value) as $key => $item) {
                $entries[(string) $key] = $item;
            }

            ksort($entries, SORT_STRING);
            $parts = [];

            foreach ($entries as $key => $item) {
                $parts[] = json_encode((string) $key, self::JSON_FLAGS).':'.$this->canonical($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        if (is_array($value)) {
            return '['.implode(',', array_map($this->canonical(...), $value)).']';
        }

        return (string) json_encode($value, self::JSON_FLAGS);
    }
}
