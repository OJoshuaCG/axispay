<?php

declare(strict_types=1);

namespace App\Modules\Shared\Logging;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use Throwable;

/**
 * Removes secrets and PII from data before it leaves the process: log records
 * (RedactSensitiveDataProcessor) and error-tracker events (SentryEventScrubber).
 * Plan sections 23.3 and 24.2; rules.md rule 10.
 *
 * Two independent layers:
 *  - Keys: any array key that names a secret or PII field has its whole value
 *    replaced, whatever the value is.
 *  - Values: every string is scanned for secret-looking tokens (Stripe and
 *    PayLink keys, webhook secrets, bearer tokens, client secrets), e-mail
 *    addresses and PAN-like digit runs.
 */
final class Redactor
{
    public const string MASK = '[REDACTED]';

    private const int MAX_DEPTH = 12;

    /**
     * Keys redacted on exact match (after lowercasing and removing everything
     * that is not a letter or digit, so `Api-Key`, `api_key` and `apiKey`
     * all become `apikey`).
     */
    private const array EXACT_KEYS = [
        'key', 'pass', 'pwd', 'pin', 'otp', 'code2fa',
        'authorization', 'proxyauthorization', 'cookie', 'setcookie',
        'card', 'cardnumber', 'pan', 'cvc', 'cvv', 'cvc2', 'expmonth', 'expyear',
        'email', 'phone', 'name', 'fullname', 'firstname', 'lastname',
        'address', 'billingaddress', 'line1', 'line2', 'postalcode', 'taxid',
        'payer', 'payerdetails',
    ];

    /**
     * Keys redacted when they contain one of these fragments.
     */
    private const array KEY_FRAGMENTS = [
        'password', 'secret', 'token', 'apikey', 'signature', 'authorization',
        'cookie', 'credential', 'privatekey', 'restrictedkey', 'email', 'phone',
        'twofactor', 'recoverycode',
    ];

    /**
     * Value patterns and their replacement.
     *
     * @var array<string, string>
     */
    private const array VALUE_PATTERNS = [
        // Stripe secret / restricted keys (sk_ must never exist here, rk_ is custodial).
        '/\b(?:sk|rk)_(?:test|live)_[A-Za-z0-9]+/' => self::MASK,
        // Webhook signing secrets (Stripe and Standard Webhooks).
        '/\bwhsec_[A-Za-z0-9+\/=]+/' => self::MASK,
        // PayLink API keys.
        '/\bplk_(?:test|live)_[A-Za-z0-9]+/' => self::MASK,
        // Stripe PaymentIntent / SetupIntent client secrets.
        '/\b(?:pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/' => self::MASK,
        // Bearer tokens in free text.
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer '.self::MASK,
        // E-mail addresses.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/' => self::MASK,
        // PAN-like runs: 13 to 19 digits, optionally separated by single spaces or dashes.
        '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/' => self::MASK,
    ];

    public function redactString(string $value): string
    {
        return (string) preg_replace(array_keys(self::VALUE_PATTERNS), array_values(self::VALUE_PATTERNS), $value);
    }

    public function isSensitiveKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $normalized = (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @return array<TKey, mixed>
     */
    public function redactArray(array $data): array
    {
        return $this->redactArrayAtDepth($data, 0);
    }

    public function redactValue(mixed $value): mixed
    {
        return $this->redactValueAtDepth($value, 0);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @return array<TKey, mixed>
     */
    private function redactArrayAtDepth(array $data, int $depth): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->isSensitiveKey($key) && $value !== null && $value !== ''
                ? self::MASK
                : $this->redactValueAtDepth($value, $depth + 1);
        }

        return $result;
    }

    private function redactValueAtDepth(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::MASK;
        }

        return match (true) {
            is_string($value) => $this->redactString($value),
            is_array($value) => $this->redactArrayAtDepth($value, $depth),
            $value instanceof Throwable => $this->redactThrowable($value, $depth),
            $value instanceof Arrayable => $this->redactValueAtDepth($value->toArray(), $depth),
            $value instanceof JsonSerializable => $this->redactValueAtDepth($value->jsonSerialize(), $depth),
            $value instanceof Stringable => $this->redactString((string) $value),
            default => $value,
        };
    }

    /**
     * Exceptions are flattened so their message and trace are scrubbed too
     * (a formatter would otherwise serialize them verbatim).
     *
     * @return array<string, mixed>
     */
    private function redactThrowable(Throwable $e, int $depth): array
    {
        $flattened = [
            'class' => $e::class,
            'message' => $this->redactString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => $this->redactString($e->getTraceAsString()),
        ];

        $previous = $e->getPrevious();

        if ($previous !== null) {
            $flattened['previous'] = $this->redactValueAtDepth($previous, $depth + 1);
        }

        return $flattened;
    }
}
