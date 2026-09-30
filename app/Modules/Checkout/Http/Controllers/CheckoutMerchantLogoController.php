<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\TenantLogo;
use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Http\Middleware\CheckoutSecurityHeaders;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /l/{token}/logo/{variant}/{version}.png (ADR-0056 part B): the
 * merchant's logo, served only on the pay host, same-origin (the checkout
 * allows images from 'self' only), under the link's own address so the URL
 * exposes no internal ID.
 *
 * The link comes from its token through the tenant-safe resolver and the
 * logo is read in that link's tenant, so a token only ever serves its own
 * merchant's logo. An invalid token, an unknown variant, an old version or a
 * missing logo answers the checkout's uniform 404. A matching version never
 * changes (every upload gets a new one), so it is cached for a year
 * (CheckoutSecurityHeaders::IMMUTABLE_ASSET). No session, no cookies: the
 * route runs without the session middleware.
 */
final readonly class CheckoutMerchantLogoController
{
    public function __invoke(Request $request, string $token, string $variant, string $version, CheckoutLinkResolver $resolver, CheckoutResponses $responses): Response|JsonResponse
    {
        $link = $resolver->resolve($token);
        $logoVariant = LogoVariant::tryFrom($variant);

        $logo = $link === null || $logoVariant === null ? null : TenantLogo::query()
            ->where('tenant_id', $link->tenant_id)
            ->where('variant', $logoVariant->value)
            ->where('version', $version)
            ->first();

        if ($logo === null) {
            return $responses->notFound();
        }

        $request->attributes->set(CheckoutSecurityHeaders::IMMUTABLE_ASSET, true);

        return new Response($logo->content, 200, [
            'Content-Type' => $logo->mime_type,
            'Content-Length' => (string) strlen($logo->content),
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
