{{--
    Order summary of the checkout (plan 11.3, DESIGN.md): who is paid, the
    description (plain text, escaped, full length), the total through
    <x-amount> (numeric font, number + ISO code) and, only when the link
    expires within 72 hours, its expiry in the tenant's time zone.
    Never the link's metadata or client reference (plan 11.3).

    Props:
        merchant    display name
        description link description
        money       App\Modules\Shared\Money\Money
        expiresAt   CarbonImmutable|null, already in the tenant's time zone
        showAmount  bool: the total is shown only while the link can be paid,
                    during a payment, or to the session that paid (plan 11.2:
                    informative pages show the description and date only)
--}}
@props(['merchant', 'description', 'money', 'expiresAt' => null, 'showAmount' => true])

<section {{ $attributes->class('flex flex-col gap-stack-sm') }} aria-label="{{ __('checkout.summary.pay_to', ['merchant' => $merchant]) }}">
    <p class="text-fg-secondary break-words">{{ __('checkout.summary.pay_to', ['merchant' => $merchant]) }}</p>
    <dl class="flex flex-col gap-stack-sm">
        <dt class="sr-only">{{ __('checkout.summary.description') }}</dt>
        <dd class="whitespace-pre-line break-words text-fg">{{ $description }}</dd>

        @if ($showAmount)
            <dt class="sr-only">{{ __('checkout.summary.total') }}</dt>
            <dd>
                <x-amount :value="$money->minorAmount" :currency="$money->currency->value" minor :signed="false" class="text-3xl font-semibold" />
            </dd>
        @endif

        {{-- Phase 6: FX legend slot. --}}

        @if ($expiresAt)
            <dt class="sr-only">{{ __('payment_links.fields.expires') }}</dt>
            <dd class="text-sm text-fg-secondary">
                {!! __('checkout.summary.expires', ['date' => '<time datetime="'.e($expiresAt->toIso8601String()).'">'.e($expiresAt->isoFormat('LLL').' '.$expiresAt->format('T')).'</time>']) !!}
            </dd>
        @endif
    </dl>
</section>
