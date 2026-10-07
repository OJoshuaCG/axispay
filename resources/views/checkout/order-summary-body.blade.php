{{--
    Content of <x-checkout.order-summary> (included by it with its props and
    slot, so the card and the bare variant share one markup). Order: who is
    paid, the description, the total, the FX legend (only when the link may be converted), the expiry, the slot (the
    legal links). No heading: the page's h1 is in the form or the status
    panel; the section is labelled by "Payment to {merchant}".
--}}
<p class="text-sm font-medium break-words text-fg-secondary">{{ __('checkout.summary.pay_to', ['merchant' => $merchant]) }}</p>

<p class="text-base whitespace-pre-line break-words text-fg">{{ $description }}</p>

@if ($showAmount)
    <dl class="flex flex-col gap-stack-xs border-t border-line pt-stack-md">
        <dt class="text-sm text-fg-secondary">{{ __('checkout.summary.total') }}</dt>
        <dd>
            <x-amount :value="$money->minorAmount" :currency="$money->currency->value" minor :signed="false" split class="text-3xl font-semibold sm:text-4xl" code-class="text-xl text-fg-secondary" />
        </dd>

        @if ($fxLegend)
            <dd class="text-sm text-fg-secondary break-words" data-fx-legend>{{ $fxLegend }}</dd>
        @endif
    </dl>
@endif

@if ($expiresAt)
    <p class="flex items-start gap-1.5 text-sm text-fg-secondary">
        <x-icon name="clock" variant="mini" size="sm" class="mt-0.5 shrink-0" />
        <span class="min-w-0 break-words">{!! __('checkout.summary.expires', ['date' => '<time datetime="'.e($expiresAt->toIso8601String()).'">'.e($expiresAt->isoFormat('LLL').' '.$expiresAt->format('T')).'</time>']) !!}</span>
    </p>
@endif

@if ($slot->hasActualContent())
    <div class="border-t border-line pt-stack-sm">
        {{ $slot }}
    </div>
@endif
