{{--
    Mobile summary cell of the payment links table (shown below the `md`
    breakpoint, instead of the desktop columns): description (two lines at
    most) and amount, then status and expiry.
--}}
@php
    /** @var \App\Modules\PaymentLinks\Models\PaymentLink $record */
    $record = $getRecord();
    $status = $record->status;
@endphp

<div class="flex w-full min-w-0 flex-col gap-1.5 whitespace-normal px-3 py-3">
    <div class="flex min-w-0 items-start justify-between gap-3">
        <span class="line-clamp-2 min-w-0 break-words text-fg">{{ $record->description }}</span>
        <span class="amount shrink-0 whitespace-nowrap text-fg" lang="{{ \App\Support\Locales::formattingLanguageTag() }}">{{ \App\Modules\Shared\Money\MoneyDisplay::format($record->money()) }}</span>
    </div>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <x-filament::badge :color="$status->color()" :icon="$status->icon()">{{ $status->label() }}</x-filament::badge>
        @if ($status->isShareable())
            <span class="text-fg-secondary">{{ \App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource::expiryLine($record) }}</span>
        @endif
    </div>
</div>
