<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Data;

use App\Modules\PaymentLinks\Models\PaymentLink;

/**
 * One page of links, newest first, and whether more exist in the direction
 * that was paged.
 */
final readonly class PaymentLinkPage
{
    /**
     * @param  list<PaymentLink>  $links
     */
    public function __construct(
        public array $links,
        public bool $hasMore,
    ) {}
}
