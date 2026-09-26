<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ids;

use Brick\Math\BigInteger;

/**
 * High-entropy secrets encoded in base62 (plan 10.2 API keys, 11.1 public
 * link tokens): `random_bytes()` read as an unsigned integer and written with
 * the 62-character alphabet, left-padded to a fixed length so every token of
 * the same size has the same length. 32 bytes give 43 characters.
 */
final class SecureToken
{
    public const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private function __construct() {}

    /**
     * @param  positive-int  $bytes
     */
    public static function base62(int $bytes = 32): string
    {
        $encoded = BigInteger::fromBytes(random_bytes($bytes), false)->toArbitraryBase(self::ALPHABET);

        return str_pad($encoded, self::lengthFor($bytes), '0', STR_PAD_LEFT);
    }

    /**
     * `$bytes` random bytes as lowercase hex (`2 × $bytes` characters).
     *
     * @param  positive-int  $bytes
     */
    public static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Characters needed to write any `$bytes`-byte value in base62. */
    public static function lengthFor(int $bytes): int
    {
        return (int) ceil($bytes * 8 / log(62, 2));
    }
}
