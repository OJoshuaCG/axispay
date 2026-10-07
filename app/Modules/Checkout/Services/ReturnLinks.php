<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use Carbon\CarbonImmutable;

/**
 * Where the payer is sent back to the merchant (ADR-0064).
 *
 * After a successful payment the link's `return_url` gets a signed proof
 * appended. The merchant verifies it with its return secret (ReturnSigningSecrets),
 * which is how its page knows the query string was written by AxisPay and not
 * typed by the payer. The proof is a convenience for the merchant's own page,
 * never the authority on the payment: the merchant confirms the payment with
 * the API or a webhook.
 *
 * Query parameters appended to the merchant's own URL, in this order:
 *
 *  - `ref`: the link's `client_reference_id` (left out when it has none);
 *  - `plink`: the link id (`plink_...`);
 *  - `payment`: the id of the payment that paid it (`pay_...`);
 *  - `status`: always `paid`;
 *  - `ts`: Unix time, in seconds, when the proof was made;
 *  - `sig`: the hex HMAC-SHA256, keyed with the secret, of
 *    `plink\npayment\nstatus\nts\nref` (an empty `ref` when there is none),
 *    one signature per active secret separated by a comma during a rotation.
 *
 * Neither the checkout token nor any gateway identifier or secret is ever part
 * of the URL. A closed link (expired, canceled) returns to the merchant with
 * the plain `return_url`: there is nothing to prove.
 */
final readonly class ReturnLinks
{
    public const string STATUS_PAID = 'paid';

    public function __construct(private ReturnSigningSecrets $secrets) {}

    /** The merchant's URL with the proof of the payment that paid the link; the plain URL without a payment to prove. */
    public function paid(PaymentLink $link): ?string
    {
        if ($link->return_url === null) {
            return null;
        }

        $attempt = PaymentAttempt::query()
            ->where('payment_link_id', $link->id)
            ->where('status', PaymentAttemptStatus::Succeeded->value)
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            return $link->return_url;
        }

        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $reference = $link->client_reference_id;
        $message = implode("\n", [$link->prefixedId(), $attempt->prefixedId(), self::STATUS_PAID, $timestamp, $reference ?? '']);
        $signatures = array_map(
            static fn (string $secret): string => hash_hmac('sha256', $message, $secret),
            $this->secrets->signingSecrets(),
        );

        $proof = [
            ...($reference !== null ? ['ref' => $reference] : []),
            'plink' => $link->prefixedId(),
            'payment' => $attempt->prefixedId(),
            'status' => self::STATUS_PAID,
            'ts' => $timestamp,
            'sig' => implode(',', $signatures),
        ];

        return self::withQuery($link->return_url, $proof);
    }

    /**
     * The merchant's URL as it is, for a link that ended without a payment.
     */
    public function closed(PaymentLink $link): ?string
    {
        return $link->return_url;
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private static function withQuery(string $url, array $parameters): string
    {
        $fragmentStart = strpos($url, '#');
        $base = $fragmentStart === false ? $url : substr($url, 0, $fragmentStart);
        $fragment = $fragmentStart === false ? '' : substr($url, $fragmentStart);

        $separator = match (true) {
            ! str_contains($base, '?') => '?',
            str_ends_with($base, '?'), str_ends_with($base, '&') => '',
            default => '&',
        };

        return $base.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986).$fragment;
    }
}
