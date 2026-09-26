<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Actions;

use App\Modules\PaymentLinks\Data\ListPaymentLinksData;
use App\Modules\PaymentLinks\Data\PaymentLinkPage;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /v1/payment_links` (plan 10.1, 10.5): the current tenant's links in
 * the current mode, newest first, filtered and paged by cursor. The cursor is
 * the link ID (ULIDs sort by creation time): `starting_after` returns older
 * links, `ending_before` newer ones.
 */
final class ListPaymentLinks
{
    public function handle(ListPaymentLinksData $data): PaymentLinkPage
    {
        $query = PaymentLink::query()
            ->when($data->status !== null, static fn (Builder $q): Builder => $q->where('status', $data->status?->value))
            ->when($data->currency !== null, static fn (Builder $q): Builder => $q->where('currency', $data->currency?->value))
            ->when($data->clientReferenceId !== null, static fn (Builder $q): Builder => $q->where('client_reference_id', $data->clientReferenceId))
            ->when($data->createdGte !== null, static fn (Builder $q): Builder => $q->where('created_at', '>=', $data->createdGte))
            ->when($data->createdLte !== null, static fn (Builder $q): Builder => $q->where('created_at', '<=', $data->createdLte));

        if ($data->endingBefore !== null) {
            $links = $query->where('id', '>', $data->endingBefore)->orderBy('id')->limit($data->limit + 1)->get()->all();
            $hasMore = count($links) > $data->limit;

            return new PaymentLinkPage(array_reverse(array_slice($links, 0, $data->limit)), $hasMore);
        }

        $links = $query
            ->when($data->startingAfter !== null, static fn (Builder $q): Builder => $q->where('id', '<', $data->startingAfter))
            ->orderByDesc('id')
            ->limit($data->limit + 1)
            ->get()
            ->all();

        return new PaymentLinkPage(array_slice($links, 0, $data->limit), count($links) > $data->limit);
    }
}
