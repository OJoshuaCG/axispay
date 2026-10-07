<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\ListRefundsData;
use App\Modules\Payments\Data\RefundPage;
use App\Modules\Payments\Models\Refund;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /v1/refunds` (plan 10.7): the current tenant's refunds in the current
 * mode, newest first, filtered and paged by cursor like the other lists. The
 * cursor is the refund ID (ULIDs sort by creation time): `starting_after`
 * returns older refunds, `ending_before` newer ones.
 */
final class ListRefunds
{
    public function handle(ListRefundsData $data): RefundPage
    {
        $query = Refund::query()
            ->when($data->paymentId !== null, static fn (Builder $q): Builder => $q->where('payment_attempt_id', $data->paymentId))
            ->when($data->status !== null, static fn (Builder $q): Builder => $q->where('status', $data->status?->value));

        if ($data->endingBefore !== null) {
            $refunds = $query->where('id', '>', $data->endingBefore)->orderBy('id')->limit($data->limit + 1)->get()->all();

            return new RefundPage(array_reverse(array_slice($refunds, 0, $data->limit)), count($refunds) > $data->limit);
        }

        $refunds = $query
            ->when($data->startingAfter !== null, static fn (Builder $q): Builder => $q->where('id', '<', $data->startingAfter))
            ->orderByDesc('id')
            ->limit($data->limit + 1)
            ->get()
            ->all();

        return new RefundPage(array_slice($refunds, 0, $data->limit), count($refunds) > $data->limit);
    }
}
