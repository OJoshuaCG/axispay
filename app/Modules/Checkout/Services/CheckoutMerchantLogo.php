<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Services\TenantLogos;
use App\Modules\Checkout\Data\MerchantLogo;
use App\Modules\PaymentLinks\Models\PaymentLink;

/**
 * The merchant's logo of a link's payment pages (ADR-0056 part B), or null
 * without a light logo (the page then shows the merchant's name). Read in
 * the link's tenant context, without the image bytes.
 */
final readonly class CheckoutMerchantLogo
{
    public function __construct(
        private TenantLogos $logos,
        private CheckoutUrls $urls,
    ) {}

    public function for(PaymentLink $link): ?MerchantLogo
    {
        $light = $this->logos->find($link->tenant_id, LogoVariant::Light);

        if ($light === null) {
            return null;
        }

        $dark = $this->logos->find($link->tenant_id, LogoVariant::Dark);

        return new MerchantLogo(
            url: $this->urls->merchantLogo($link, LogoVariant::Light, $light->version),
            darkUrl: $dark !== null ? $this->urls->merchantLogo($link, LogoVariant::Dark, $dark->version) : null,
            width: $light->width,
            height: $light->height,
            darkWidth: $dark?->width,
            darkHeight: $dark?->height,
        );
    }
}
