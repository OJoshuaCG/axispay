<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

use App\Modules\ApiKeys\Data\GeneratedApiKey;
use App\Modules\Shared\Ids\SecureToken;

/**
 * Key format of plan 10.2: `axp_{test|live}_{32 random bytes in base62}`
 * (43 characters of secret). The prefix names the mode, so a live key is
 * recognizable at a glance and can never be taken for a test key.
 */
final class ApiKeyGenerator
{
    public const int SECRET_BYTES = 32;

    /** Characters of the secret kept visible in `key_prefix`. */
    private const int VISIBLE_SECRET_CHARS = 4;

    public function generate(bool $livemode): GeneratedApiKey
    {
        $modePrefix = self::modePrefix($livemode);
        $secret = SecureToken::base62(self::SECRET_BYTES);
        $plaintext = $modePrefix.$secret;

        return new GeneratedApiKey(
            plaintext: $plaintext,
            hash: self::hash($plaintext),
            prefix: $modePrefix.substr($secret, 0, self::VISIBLE_SECRET_CHARS),
            last4: substr($secret, -4),
        );
    }

    /** `axp_live_` or `axp_test_`. */
    public static function modePrefix(bool $livemode): string
    {
        return config()->string('axispay.api_key_prefix').'_'.($livemode ? 'live' : 'test').'_';
    }

    /**
     * SHA-256 is right here: the key is a high-entropy random value, not a
     * password (plan 10.2).
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** Syntactic check; returns the mode the prefix names, or null. */
    public static function modeOf(string $candidate): ?bool
    {
        $prefix = preg_quote(config()->string('axispay.api_key_prefix'), '/');
        $length = SecureToken::lengthFor(self::SECRET_BYTES);

        if (preg_match("/^{$prefix}_(test|live)_[0-9A-Za-z]{{$length}}$/D", $candidate, $match) !== 1) {
            return null;
        }

        return $match[1] === 'live';
    }
}
