{{--
    Mobile summary cell of the API keys table (below `md`): name, masked key
    and status.
--}}
@php
    /** @var \App\Modules\ApiKeys\Models\ApiKey $record */
    $record = $getRecord();
    $status = $record->status();
@endphp

<div class="flex w-full min-w-0 flex-col gap-1 whitespace-normal px-3 py-3">
    <span class="min-w-0 break-words text-fg">{{ $record->name }}</span>
    <span class="font-numeric min-w-0 break-all text-fg-secondary">{{ $record->maskedKey() }}</span>
    <div>
        <x-filament::badge :color="$status->color()" :icon="$status->icon()">{{ $status->label() }}</x-filament::badge>
    </div>
</div>
