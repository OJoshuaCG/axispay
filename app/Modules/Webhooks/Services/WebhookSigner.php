<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Standard Webhooks signatures (plan 15.5, ADR-0008): HMAC-SHA256 over
 * `{webhook-id}.{webhook-timestamp}.{body}` with the bytes of the secret
 * after `whsec_`, sent as `v1,<base64>`. During a rotation the header holds
 * both signatures separated by a space, as the specification allows.
 * Secret parameters are marked sensitive: they never appear in stack traces.
 */
final class WebhookSigner
{
    public const string SECRET_PREFIX = 'whsec_';

    /** Receivers reject a `webhook-timestamp` further than this from their clock. */
    public const int TOLERANCE_SECONDS = 300;

    /** Plan 15.1: `whsec_` + base64 of 32 random bytes. */
    public function generateSecret(): string
    {
        return self::SECRET_PREFIX.base64_encode(random_bytes(32));
    }

    public function sign(#[SensitiveParameter] string $secret, string $webhookId, int $timestamp, string $body): string
    {
        $signature = base64_encode(hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$body}", $this->secretBytes($secret), binary: true));

        return "v1,{$signature}";
    }

    /**
     * The `webhook-signature` header: one `v1,<signature>` per secret.
     *
     * @param  list<string>  $secrets
     */
    public function header(#[SensitiveParameter] array $secrets, string $webhookId, int $timestamp, string $body): string
    {
        if ($secrets === []) {
            throw new InvalidArgumentException('A webhook is signed with at least one secret.');
        }

        return implode(' ', array_map(fn (#[SensitiveParameter] string $secret): string => $this->sign($secret, $webhookId, $timestamp, $body), $secrets));
    }

    /**
     * Receiver-side check, as documented for integrators: any signature of
     * the header matches (constant-time) and the timestamp is within the
     * tolerance.
     */
    public function verify(#[SensitiveParameter] string $secret, string $webhookId, int $timestamp, string $body, string $header, int $now, int $toleranceSeconds = self::TOLERANCE_SECONDS): bool
    {
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = $this->sign($secret, $webhookId, $timestamp, $body);

        foreach (explode(' ', $header) as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function secretBytes(#[SensitiveParameter] string $secret): string
    {
        if (! str_starts_with($secret, self::SECRET_PREFIX)) {
            throw new InvalidArgumentException('A webhook secret starts with whsec_.');
        }

        $bytes = base64_decode(substr($secret, strlen(self::SECRET_PREFIX)), strict: true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('A webhook secret is base64 after whsec_.');
        }

        return $bytes;
    }
}
