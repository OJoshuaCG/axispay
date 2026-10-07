<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\ListPaymentsData;
use App\Modules\Payments\Data\PaymentPage;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /v1/payments` (plan 10.1, 10.6): the current tenant's payments in the
 * current mode, newest first, filtered and paged by cursor like the other
 * lists. The cursor is the payment ID (ULIDs sort by creation time):
 * `starting_after` returns older payments, `ending_before` newer ones. A
 * payment is one gateway attempt of a link (rules.md, plan 9.2).
 */
final class ListPayments
{
    /** Microsecond-precise binding: a Carbon binding would drop the fraction. */
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function handle(ListPaymentsData $data): PaymentPage
    {
        $query = PaymentAttempt::query()
            ->with('link')
            ->when($data->status !== null, static fn (Builder $q): Builder => $q->where('status', $data->status?->value))
            ->when($data->paymentLinkId !== null, static fn (Builder $q): Builder => $q->where('payment_link_id', $data->paymentLinkId))
            ->when($data->createdGte !== null, static fn (Builder $q): Builder => $q->where('created_at', '>=', $data->createdGte?->utc()->format(self::TIME_FORMAT)))
            ->when($data->createdLte !== null, static fn (Builder $q): Builder => $q->where('created_at', '<=', $data->createdLte?->utc()->format(self::TIME_FORMAT)));

        if ($data->endingBefore !== null) {
            $payments = $query->where('id', '>', $data->endingBefore)->orderBy('id')->limit($data->limit + 1)->get()->all();

            return new PaymentPage(array_reverse(array_slice($payments, 0, $data->limit)), count($payments) > $data->limit);
        }

        $payments = $query
            ->when($data->startingAfter !== null, static fn (Builder $q): Builder => $q->where('id', '<', $data->startingAfter))
            ->orderByDesc('id')
            ->limit($data->limit + 1)
            ->get()
            ->all();

        return new PaymentPage(array_slice($payments, 0, $data->limit), count($payments) > $data->limit);
    }
}
