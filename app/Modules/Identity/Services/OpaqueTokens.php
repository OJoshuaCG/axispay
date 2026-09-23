<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/**
 * Single-use secret tokens (invitations, impersonation hand-off). The plain
 * token (256 bits, base64url) only travels in a signed link; the database
 * keeps its SHA-256 in hex (ascii_bin, unique).
 */
final class OpaqueTokens
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
