{{--
    Order summary of the checkout (plan 11.3, docs/frontend/checkout-design.md,
    ADR-0056 part C): who is paid, the description (plain text, escaped, full
    length), the total through <x-amount split> (numeric font, the number
    never broken, the code wrapping below it in a narrow card) with a
    visible "Total to pay" label and, only when the link expires within 72
    hours, its expiry in the tenant's time zone. Never the link's metadata
    or client reference (plan 11.3). Never sticky: a summary taller than the
    viewport would be unreachable.

    Props:
        merchant    display name
        description link description
        money       App\Modules\Shared\Money\Money
        expiresAt   CarbonImmutable|null, already in the tenant's time zone
        showAmount  bool: the total is shown only while the link can be paid,
                    during a payment, or to the session that paid (plan 11.2:
                    informative pages show the description and date only)
        lineItems   list<LineItem>: the merchant's breakdown (ADR-0064), shown
                    above the total while the amount is shown
        fxLegend    string|null: what a card issued in Mexico is charged in MXN
                    (plan 11.3), under the total, when the link may be converted
        bare        bool: false (default) = the left card of the two-card
                    layout (<x-checkout.card>); true = no card, for the
                    single-card states, where it sits inside the state's card

    Slot: last, under a hairline; the page puts the merchant's legal links
    there (ADR-0056).
--}}
@props(['merchant', 'description', 'money', 'expiresAt' => null, 'showAmount' => true, 'bare' => false, 'fxLegend' => null, 'lineItems' => []])

@php
    $label = __('checkout.summary.pay_to', ['merchant' => $merchant]);
@endphp

@if ($bare)
    <section {{ $attributes->class('flex flex-col gap-stack-md') }} aria-label="{{ $label }}">
        @include('checkout.order-summary-body')
    </section>
@else
    <x-checkout.card {{ $attributes->class('flex flex-col gap-stack-md') }} aria-label="{{ $label }}">
        @include('checkout.order-summary-body')
    </x-checkout.card>
@endif
