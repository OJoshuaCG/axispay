<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * A pre-payment validator that always answers the same decision and counts
 * how often it was asked (the merchant is asked once per attempt, ADR-0050).
 */
final class CountingValidator implements PrePaymentValidator
{
    public int $calls = 0;

    public function __construct(private readonly PrePaymentDecision $decision) {}

    /** Binds a new counting validator into the container and returns it. */
    public static function install(PrePaymentDecision $decision): self
    {
        $validator = new self($decision);
        app()->instance(PrePaymentValidator::class, $validator);

        return $validator;
    }

    public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
    {
        $this->calls++;

        return $this->decision;
    }
}
