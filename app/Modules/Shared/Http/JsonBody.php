<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use Illuminate\Http\Request;
use stdClass;

/**
 * The JSON object of an API request body (plan 10.1): `Content-Type:
 * application/json` is required when there is a body, and the body must be
 * a JSON object. An empty body (or `[]`) is an empty object.
 */
final class JsonBody
{
    private function __construct() {}

    /**
     * Top-level fields by name. Nested JSON objects are kept as `stdClass`
     * so a caller can tell an object from a list (`{"0": "a"}` is not
     * `["a"]`); nested lists are PHP lists.
     *
     * @return array<string, mixed>
     */
    public static function of(Request $request): array
    {
        $raw = $request->getContent();

        if (trim($raw) === '') {
            return [];
        }

        if (! $request->isJson()) {
            throw ApiException::of(ApiErrorCode::ParameterInvalid, 'Requests with a body must use Content-Type: application/json.');
        }

        $decoded = json_decode($raw, false, 64);

        // `[]` is accepted as an empty object: many JSON encoders write an
        // empty map that way.
        if ($decoded === []) {
            return [];
        }

        if (! $decoded instanceof stdClass) {
            throw ApiException::of(ApiErrorCode::ParameterInvalid, 'The request body must be a valid JSON object.');
        }

        $fields = [];

        foreach (get_object_vars($decoded) as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    /**
     * The entries of a JSON object with their keys as strings, or null when
     * the value is not an object. Accepts a decoded `stdClass` or an
     * associative PHP array (panel input); a non-empty list is not an object.
     *
     * @return list<array{0: string, 1: mixed}>|null
     */
    public static function objectEntries(mixed $value): ?array
    {
        if (! $value instanceof stdClass && ! (is_array($value) && ($value === [] || ! array_is_list($value)))) {
            return null;
        }

        $entries = [];

        foreach ($value instanceof stdClass ? get_object_vars($value) : $value as $key => $item) {
            $entries[] = [(string) $key, $item];
        }

        return $entries;
    }
}
