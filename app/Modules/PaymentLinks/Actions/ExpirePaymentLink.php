<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Actions;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * `active` → `expired` once `expires_at` is reached (plan 9.1), in the
 * current tenant context and under a row lock. A link that changed state in
 * the meantime (paid, canceled, a payment in progress) is left alone.
 * Returns whether the link was expired.
 */
final readonly class ExpirePaymentLink
{
    public function __construct(private PaymentLinkStateMachine $machine) {}

    public function handle(string $linkId): bool
    {
        return DB::transaction(function () use ($linkId): bool {
            $locked = PaymentLink::query()->lockForUpdate()->find($linkId);

            if ($locked === null || $locked->status !== PaymentLinkStatus::Active || ! $locked->isPastExpiry()) {
                return false;
            }

            $this->machine->expire($locked);

            return true;
        });
    }
}
