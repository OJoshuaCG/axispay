<?php

declare(strict_types=1);

namespace Tests\Support;

use JsonException;
use LogicException;
use Stripe\WebhookSignature;

/**
 * Stripe payload fixtures, versioned with the pinned API version (plan
 * 26.3): tests/Fixtures/Stripe/<api_version>/*.json. Placeholders
 * ({{account}}, {{event}}, {{livemode}}...) are replaced per test.
 */
final class StripeFixtures
{
    public const string API_VERSION = '2026-08-26.dahlia';

    /**
     * @param  array<string, string|bool>  $replace
     * @return array<mixed>
     */
    public static function load(string $name, array $replace = []): array
    {
        $path = dirname(__DIR__).'/Fixtures/Stripe/'.self::API_VERSION.'/'.$name.'.json';
        $raw = (string) file_get_contents($path);

        foreach ($replace as $key => $value) {
            $raw = str_replace(
                is_bool($value) ? '"{{'.$key.'}}"' : '{{'.$key.'}}',
                is_bool($value) ? ($value ? 'true' : 'false') : $value,
                $raw,
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException("Invalid fixture {$name}: {$e->getMessage()}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * An account object as GET /v1/account returns it.
     *
     * @param  list<string>  $currentlyDue
     * @return array<mixed>
     */
    public static function account(string $id, bool $chargesEnabled = true, string $country = 'MX', array $currentlyDue = []): array
    {
        $account = self::load('account', ['account' => $id, 'country' => $country, 'charges_enabled' => $chargesEnabled, 'payouts_enabled' => $chargesEnabled, 'details_submitted' => $chargesEnabled]);
        $requirements = is_array($account['requirements'] ?? null) ? $account['requirements'] : [];
        $requirements['currently_due'] = $currentlyDue;
        $requirements['disabled_reason'] = $chargesEnabled ? null : 'requirements.past_due';
        $account['requirements'] = $requirements;

        return $account;
    }

    /**
     * A refund as GET /v1/refunds/{id} returns it. An empty `$reference` is a
     * refund without our metadata (made in Stripe's Dashboard).
     *
     * @param  array<mixed>  $changes
     * @return array<mixed>
     */
    public static function refund(string $id, string $status, string $paymentIntent = 'pi_Ref0001', string $reference = '', array $changes = []): array
    {
        return array_replace_recursive(self::load('refund', ['id' => $id, 'status' => $status, 'payment_intent' => $paymentIntent, 'reference' => $reference]), $changes);
    }

    /**
     * A dispute as GET /v1/disputes/{id} returns it.
     *
     * @param  array<mixed>  $changes
     * @return array<mixed>
     */
    public static function dispute(string $id, string $status, string $paymentIntent = 'pi_Ref0001', array $changes = []): array
    {
        return array_replace_recursive(self::load('dispute', ['id' => $id, 'status' => $status, 'payment_intent' => $paymentIntent]), $changes);
    }

    /**
     * A signed webhook delivery: [raw body, Stripe-Signature header].
     *
     * @param  array<mixed>  $event
     * @return array{0: string, 1: string}
     */
    public static function signed(array $event, string $secret, ?int $timestamp = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [$body, WebhookSignature::generateSignatureHeader($body, $secret, $timestamp ?? time())];
    }
}
