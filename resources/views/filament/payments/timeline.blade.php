{{--
    Timeline of one payment (plan 20.2), oldest first, built by
    App\Modules\Payments\Filament\Support\PaymentTimeline. Each step is a
    title and a time (never only an icon or a color); a code (decline code,
    `val_...`, `evt_...`) stays visible for support.

    Props: entries (list<array{time: CarbonImmutable, title: string,
           detail: ?string, code: ?string, color: string, icon: string}>)
--}}
@php
    $entries = isset($entries) && is_array($entries) ? $entries : [];
    $tones = [
        'success' => 'text-success',
        'danger' => 'text-error',
        'warning' => 'text-warning',
        'info' => 'text-info',
        'gray' => 'text-fg-secondary',
    ];
@endphp

<ol class="flex flex-col gap-stack-sm" data-payment-timeline>
    @foreach ($entries as $entry)
        <li class="flex items-start gap-3">
            {{-- Decorative: the title says what happened (x-icon renders aria-hidden without a label). --}}
            <x-icon :name="$entry['icon']" size="sm" aria-hidden="true" @class(['mt-0.5 shrink-0', $tones[$entry['color']] ?? 'text-fg-secondary']) />
            <div class="flex min-w-0 flex-col">
                <span class="text-sm font-medium text-fg">{{ $entry['title'] }}</span>
                <span class="text-sm text-fg-secondary">
                    <time datetime="{{ $entry['time']->toIso8601ZuluString() }}">{{ \App\Modules\Payments\Filament\Support\PaymentPresenter::date($entry['time']) }}</time>
                </span>
                @if ($entry['detail'])
                    <span class="text-sm text-fg">{{ $entry['detail'] }}</span>
                @endif
                @if ($entry['code'])
                    <code class="font-numeric break-all text-xs text-fg-secondary">{{ $entry['code'] }}</code>
                @endif
            </div>
        </li>
    @endforeach
</ol>
