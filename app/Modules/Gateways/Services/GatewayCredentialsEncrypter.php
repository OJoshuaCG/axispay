<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Data\EncryptedCredential;
use App\Modules\Gateways\Exceptions\GatewayCredentialsKeyException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;

/**
 * Encrypts merchant gateway credentials (api_key method: the restricted key
 * and the webhook signing secret) with a DEDICATED key, never APP_KEY (plan
 * 12.3.3, 23.2, rules.md rule 5b). AES-256-GCM through Laravel's encrypter.
 *
 * Keys are versioned for rotation: new ciphertext always uses the current
 * version; older versions stay readable while they are listed in
 * GATEWAY_CREDENTIALS_PREVIOUS_KEYS. `axispay:rotate-gateway-credentials-key`
 * re-encrypts every stored credential with the current version.
 *
 * Decrypted values only live in memory for the duration of a call; nothing
 * here logs, caches or serializes them.
 */
final class GatewayCredentialsEncrypter
{
    public const string CIPHER = 'aes-256-gcm';

    /** @var array<int, Encrypter> */
    private array $encrypters = [];

    public function currentVersion(): int
    {
        $version = config('axispay.gateway_credentials.key_version');

        if (! is_int($version) || $version < 1) {
            throw new GatewayCredentialsKeyException('GATEWAY_CREDENTIALS_KEY_VERSION must be a positive integer.');
        }

        return $version;
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): EncryptedCredential
    {
        $version = $this->currentVersion();

        return new EncryptedCredential($this->encrypterFor($version)->encryptString($plaintext), $version);
    }

    public function decrypt(string $ciphertext, ?int $keyVersion): string
    {
        $version = $keyVersion ?? throw new GatewayCredentialsKeyException('The credential has no key version.');

        try {
            return $this->encrypterFor($version)->decryptString($ciphertext);
        } catch (DecryptException) {
            // The original exception is dropped on purpose: it carries nothing
            // useful and must never be the channel for key material.
            throw new GatewayCredentialsKeyException("The credential cannot be decrypted with key version {$version}.");
        }
    }

    /** True when the key version is readable (current or listed as previous). */
    public function canDecrypt(int $keyVersion): bool
    {
        return array_key_exists($keyVersion, $this->keys());
    }

    private function encrypterFor(int $version): Encrypter
    {
        if (isset($this->encrypters[$version])) {
            return $this->encrypters[$version];
        }

        $key = $this->keys()[$version] ?? throw new GatewayCredentialsKeyException("No gateway credentials key is configured for version {$version}.");

        return $this->encrypters[$version] = new Encrypter($key, self::CIPHER);
    }

    /**
     * @return array<int, string> version => raw 32-byte key
     */
    private function keys(): array
    {
        $current = $this->decodeKey(config('axispay.gateway_credentials.key'), 'GATEWAY_CREDENTIALS_KEY');
        $keys = [$this->currentVersion() => $current];
        $previous = config('axispay.gateway_credentials.previous_keys');

        foreach (array_filter(array_map('trim', explode(',', is_string($previous) ? $previous : ''))) as $entry) {
            [$version, $encoded] = array_pad(explode(':', $entry, 2), 2, '');

            if (! ctype_digit($version) || (int) $version < 1) {
                throw new GatewayCredentialsKeyException('GATEWAY_CREDENTIALS_PREVIOUS_KEYS entries must look like "<version>:base64:<key>".');
            }

            $keys[(int) $version] ??= $this->decodeKey($encoded, 'GATEWAY_CREDENTIALS_PREVIOUS_KEYS');
        }

        return $keys;
    }

    private function decodeKey(mixed $configured, string $name): string
    {
        if (! is_string($configured) || $configured === '') {
            throw new GatewayCredentialsKeyException("{$name} is not configured.");
        }

        $raw = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;

        if (! is_string($raw) || strlen($raw) !== 32) {
            throw new GatewayCredentialsKeyException("{$name} must be \"base64:\" followed by 32 random bytes encoded in base64.");
        }

        if ($configured === config('app.key')) {
            throw new GatewayCredentialsKeyException("{$name} must be different from APP_KEY.");
        }

        return $raw;
    }
}
