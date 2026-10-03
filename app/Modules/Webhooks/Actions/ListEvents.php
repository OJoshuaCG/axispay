<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Webhooks\Data\EventPage;
use App\Modules\Webhooks\Data\ListEventsData;
use App\Modules\Webhooks\Services\EventHistory;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /v1/events` (plan 10.1, 10.8): the current tenant's event history in
 * the current mode, newest first, filtered and paged by cursor like the
 * other lists. The cursor is the event ID (ULIDs sort by creation time):
 * `starting_after` returns older events, `ending_before` newer ones. Only the
 * retention window is visible and the `ping` test event never is.
 */
final readonly class ListEvents
{
    public function __construct(private EventHistory $history) {}

    public function handle(ListEventsData $data): EventPage
    {
        $query = $this->history->visible()
            ->when($data->type !== null, static fn (Builder $q): Builder => $q->where('type', $data->type?->value))
            ->when($data->createdGte !== null, static fn (Builder $q): Builder => $q->where('created_at', '>=', $data->createdGte?->utc()->format(EventHistory::TIME_FORMAT)))
            ->when($data->createdLte !== null, static fn (Builder $q): Builder => $q->where('created_at', '<=', $data->createdLte?->utc()->format(EventHistory::TIME_FORMAT)));

        if ($data->endingBefore !== null) {
            $events = $query->where('id', '>', $data->endingBefore)->orderBy('id')->limit($data->limit + 1)->get()->all();

            return new EventPage(array_reverse(array_slice($events, 0, $data->limit)), count($events) > $data->limit);
        }

        $events = $query
            ->when($data->startingAfter !== null, static fn (Builder $q): Builder => $q->where('id', '<', $data->startingAfter))
            ->orderByDesc('id')
            ->limit($data->limit + 1)
            ->get()
            ->all();

        return new EventPage(array_slice($events, 0, $data->limit), count($events) > $data->limit);
    }
}
