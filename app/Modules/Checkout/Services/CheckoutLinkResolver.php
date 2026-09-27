<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkLookup;
use App\Modules\Tenancy\TenantContext;

/**
 * Checkout entry point (plan 6.3): public token → link through
 * PaymentLinkLookup (the only cross-tenant reader), then the tenant context
 * of that link is set for the rest of the request and the link is read again
 * through the tenant scope. An active link past its expiry is expired right
 * away (lazy check, plan 9.1), so the page never offers a dead link.
 */
final readonly class CheckoutLinkResolver
{
    public function __construct(
        private PaymentLinkLookup $lookup,
        private TenantContext $context,
        private ExpirePaymentLink $expire,
    ) {}

    public function resolve(string $token): ?PaymentLink
    {
        $row = $this->lookup->forPublicToken($token);

        if ($row === null) {
            return null;
        }

        $this->context->set($row->tenant_id, $row->livemode);
        $link = PaymentLink::query()->find($row->id);

        if ($link !== null && $link->status === PaymentLinkStatus::Active && $link->isPastExpiry()) {
            $this->expire->handle($link->id);
            $link->refresh();
        }

        return $link;
    }
}
