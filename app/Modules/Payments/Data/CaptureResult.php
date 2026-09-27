<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Models\PaymentAttempt;

final readonly class CaptureResult
{
    public function __construct(
        public CaptureOutcome $outcome,
        public PaymentAttempt $attempt,
        public ?string $payerMessage = null,
    ) {}
}
