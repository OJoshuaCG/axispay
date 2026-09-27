<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkUrl;

/**
 * URLs of the checkout endpoints of a link (plan 11.5), on the same base as
 * the public link (PaymentLinkUrl), so local development
 * (`AXISPAY_PAY_BASE_URL`) and production build them the same way.
 */
final class CheckoutUrls
{
    public function page(PaymentLink $link): string
    {
        return PaymentLinkUrl::for($link);
    }

    public function complete(PaymentLink $link): string
    {
        return PaymentLinkUrl::for($link).'/complete';
    }

    public function attempts(PaymentLink $link): string
    {
        return PaymentLinkUrl::for($link).'/attempts';
    }

    public function continue(PaymentLink $link): string
    {
        return PaymentLinkUrl::for($link).'/attempts/continue';
    }

    public function status(PaymentLink $link): string
    {
        return PaymentLinkUrl::for($link).'/status';
    }

    public function sandboxNextAction(PaymentLink $link): string
    {
        return PaymentLinkUrl::for($link).'/sandbox/next-action';
    }
}
