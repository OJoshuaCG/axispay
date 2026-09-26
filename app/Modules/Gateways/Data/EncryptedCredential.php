<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * A credential encrypted by GatewayCredentialsEncrypter and the version of
 * the key that encrypted it (`credentials_key_version`).
 */
final readonly class EncryptedCredential
{
    public function __construct(
        public string $ciphertext,
        public int $keyVersion,
    ) {}
}
