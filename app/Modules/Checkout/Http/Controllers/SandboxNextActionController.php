<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Services\CheckoutLinkResolver;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Gateways\Sandbox\SandboxPaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sandbox only (ADR-0051; the route exists only while SandboxMode is on):
 * the browser stub's "bank" answers a 3D Secure challenge, as the real bank
 * would through Stripe. Refuses to do anything outside the sandbox.
 */
final readonly class SandboxNextActionController
{
    public function __invoke(Request $request, string $token, CheckoutLinkResolver $resolver, SandboxPaymentGateway $sandbox): JsonResponse
    {
        abort_unless(SandboxMode::enabled(), 404);
        abort_if($resolver->resolve($token) === null, 404);

        $secret = $request->input('client_secret');

        if (is_string($secret) && str_starts_with($secret, 'pi_sandbox_')) {
            $sandbox->completeNextAction($secret, $request->boolean('approved'));
        }

        return new JsonResponse(['ok' => true]);
    }
}
