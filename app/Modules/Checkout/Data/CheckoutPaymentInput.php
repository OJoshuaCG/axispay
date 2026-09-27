<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use SensitiveParameter;

/**
 * One press of "Pay" (plan 11.4): the confirmation token created by the
 * gateway SDK in the browser, the raw payer fields (validated by the
 * action), the Turnstile token when required, and request metadata.
 */
final readonly class CheckoutPaymentInput
{
    /**
     * @param  array<mixed>  $payer
     */
    public function __construct(
        public string $confirmationToken,
        #[SensitiveParameter] public array $payer,
        #[SensitiveParameter] public ?string $turnstileToken,
        public ?string $clientIp,
        public ?string $userAgent,
        public int $sessionDeclines,
    ) {}
}
