<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http;

use Illuminate\Http\Request;

/**
 * What the payer's anonymous checkout session remembers per link (plan 11.5,
 * 11.7): how many of their confirmations were declined (Turnstile after a
 * decline in the same session) and whether this session started the payment
 * that paid the link (the "Payment complete" page instead of "already
 * paid"). No payer data.
 */
final class CheckoutSession
{
    public static function declines(Request $request, string $linkId): int
    {
        $value = $request->hasSession() ? $request->session()->get("checkout.{$linkId}.declines", 0) : 0;

        return is_int($value) ? $value : 0;
    }

    public static function recordDecline(Request $request, string $linkId): void
    {
        if ($request->hasSession()) {
            $request->session()->put("checkout.{$linkId}.declines", self::declines($request, $linkId) + 1);
        }
    }

    /** This session was handed the next action (3D Secure) of this attempt. */
    public static function awaitNextAction(Request $request, string $linkId, string $attemptId): void
    {
        if ($request->hasSession()) {
            $request->session()->put("checkout.{$linkId}.next_action", $attemptId);
        }
    }

    /** The attempt this session may continue after its next action, taken once. */
    public static function takeNextAction(Request $request, string $linkId): ?string
    {
        $value = $request->hasSession() ? $request->session()->pull("checkout.{$linkId}.next_action") : null;

        return is_string($value) ? $value : null;
    }

    public static function markPaying(Request $request, string $linkId): void
    {
        if ($request->hasSession()) {
            $request->session()->put("checkout.{$linkId}.paid", true);
        }
    }

    public static function paidHere(Request $request, string $linkId): bool
    {
        return $request->hasSession() && $request->session()->get("checkout.{$linkId}.paid") === true;
    }
}
