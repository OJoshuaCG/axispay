<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PayerDetails;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Shared\Time\IsoDateTime;
use Carbon\CarbonImmutable;

/**
 * The body of a pre-payment validation call (plan 15.8.3), type
 * `payment.pre_validation`:
 *
 *  - `payment`: the ID of the payment attempt (`pay_…`), which is the same in
 *    the immediate retry and in every later call about that attempt, so the
 *    merchant can tell the attempts of one link apart and recognize a repeat
 *    (ADR-0061);
 *  - `payment_link`: our identifiers and the integrator's own references
 *    (the top-level `livemode` completes them);
 *  - `charge`: the amount and currency that will be captured (already
 *    converted when FX applies);
 *  - `card`: the brand and the country only; nothing else of the card is
 *    ever sent (we never receive it);
 *  - `payer`: the e-mail and the full name, when the link collected them.
 *
 * Built by HttpPrePaymentValidator (real calls) and by
 * TestValidationEndpoint (`sample()`, with `test: true`).
 */
final class ValidationPayload
{
    public const string TYPE = 'payment.pre_validation';

    /**
     * @return array<string, mixed>
     */
    public static function forAttempt(string $callPrefixedId, PaymentLink $link, PaymentAttempt $attempt, int $attemptNumber, CarbonImmutable $now): array
    {
        $charge = $attempt->money();
        $metadata = $link->metadata ?? [];

        return [
            'id' => $callPrefixedId,
            'type' => self::TYPE,
            'livemode' => $link->livemode,
            'test' => false,
            'created_at' => IsoDateTime::format($now),
            'attempt_number' => $attemptNumber,
            'data' => [
                'payment' => [
                    'id' => $attempt->prefixedId(),
                ],
                'payment_link' => [
                    'id' => $link->prefixedId(),
                    'client_reference_id' => $link->client_reference_id,
                    'metadata' => $metadata === [] ? (object) [] : $metadata,
                    'description' => $link->description,
                    'amount' => $link->money()->toDecimalString(),
                    'currency' => $link->currency->value,
                    'expires_at' => IsoDateTime::format($link->expires_at),
                ],
                'charge' => [
                    'amount' => $charge->toDecimalString(),
                    'currency' => $charge->currency->value,
                    // Phase 6 adds the source and the effective rate of the quote.
                    'fx' => ['applied' => $attempt->fx_quote_id !== null],
                ],
                'card' => [
                    'brand' => $attempt->card_brand,
                    'country' => $attempt->card_country,
                ],
                'payer' => self::payer($attempt),
            ],
        ];
    }

    /**
     * The example sent by "Test validation" (plan 15.8.1): made-up data,
     * `test: true`, no link or payment behind it.
     *
     * @return array<string, mixed>
     */
    public static function sample(string $callPrefixedId, bool $livemode, CarbonImmutable $now): array
    {
        return [
            'id' => $callPrefixedId,
            'type' => self::TYPE,
            'livemode' => $livemode,
            'test' => true,
            'created_at' => IsoDateTime::format($now),
            'attempt_number' => 1,
            'data' => [
                'payment' => [
                    'id' => PrefixedId::encode(ResourceType::Payment, Ulid::generate()),
                ],
                'payment_link' => [
                    'id' => PrefixedId::encode(ResourceType::PaymentLink, Ulid::generate()),
                    'client_reference_id' => 'TEST-1029',
                    'metadata' => ['order_id' => 'TEST-1029'],
                    'description' => 'Test order — 2 items',
                    'amount' => '1500.00',
                    'currency' => 'USD',
                    'expires_at' => IsoDateTime::format($now->addDay()),
                ],
                'charge' => [
                    'amount' => '1500.00',
                    'currency' => 'USD',
                    'fx' => ['applied' => false],
                ],
                'card' => ['brand' => 'visa', 'country' => 'MX'],
                'payer' => ['email' => 'payer@example.com', 'full_name' => 'Test Payer'],
            ],
        ];
    }

    /**
     * The exact bytes sent and signed.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{email?: string, full_name?: string}|null
     */
    private static function payer(PaymentAttempt $attempt): ?array
    {
        $data = PayerDetails::query()->where('payment_attempt_id', $attempt->id)->first()->data ?? [];
        $payer = [];

        foreach ([PayerField::Email, PayerField::FullName] as $field) {
            $value = $data[$field->value] ?? null;

            if (is_string($value) && $value !== '') {
                $payer[$field->value] = $value;
            }
        }

        return $payer !== [] ? $payer : null;
    }
}
