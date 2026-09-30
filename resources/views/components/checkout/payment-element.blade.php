{{--
    Mount point of the gateway's card form (Stripe Payment Element, or the
    sandbox stub). A skeleton shaped like what will appear reserves the
    space (no layout shift, no shimmer) until the element reports `ready`;
    the script removes it, and the reserved height goes with it (the height
    is only kept while the skeleton exists). Stripe's card form, as Stripe
    lays it out: the card number across; expiry and CVC side by side; the
    country across; 240px (min-h-60). The sandbox stub: one label and field
    pair and a notice line, 84px (min-h-21). `sandboxLabels` (JSON) carries
    the stub's translated copy.

    Props: sandbox (bool)
--}}
@props(['sandbox' => false])

<div {{ $attributes->class('flex flex-col gap-stack-sm') }}>
    <p id="payment-element-label" class="text-base font-semibold text-fg">{{ __('checkout.card.heading') }}</p>

    <div @class(['relative', 'has-[[data-payment-skeleton]]:min-h-21' => $sandbox, 'has-[[data-payment-skeleton]]:min-h-60' => ! $sandbox]) aria-labelledby="payment-element-label" role="group">
        <div data-payment-element
             @if ($sandbox)
                 data-sandbox-labels="{{ json_encode([
                     'label' => __('checkout.sandbox.label'),
                     'notice' => __('checkout.card.sandbox'),
                     'scenarios' => __('checkout.sandbox.scenario'),
                     'bank' => __('checkout.sandbox.bank'),
                 ], JSON_UNESCAPED_UNICODE) }}"
             @endif
        ></div>

        <div data-payment-skeleton @class(['absolute inset-0 flex flex-col', 'gap-stack-xs' => $sandbox, 'gap-stack-md' => ! $sandbox]) aria-hidden="true">
            @if ($sandbox)
                <div class="flex flex-col gap-stack-xs">
                    <div class="h-4 w-24 rounded-md bg-surface-alt"></div>
                    <div class="h-11 w-full rounded-md bg-surface-alt"></div>
                </div>
                <div class="h-4 w-3/4 rounded-md bg-surface-alt"></div>
            @else
                <div class="flex flex-col gap-stack-xs">
                    <div class="h-4 w-28 rounded-md bg-surface-alt"></div>
                    <div class="h-11 w-full rounded-md bg-surface-alt"></div>
                </div>
                <div class="grid grid-cols-2 gap-stack-sm">
                    @foreach (range(1, 2) as $half)
                        <div class="flex flex-col gap-stack-xs">
                            <div class="h-4 w-16 rounded-md bg-surface-alt"></div>
                            <div class="h-11 w-full rounded-md bg-surface-alt"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-col gap-stack-xs">
                    <div class="h-4 w-20 rounded-md bg-surface-alt"></div>
                    <div class="h-11 w-full rounded-md bg-surface-alt"></div>
                </div>
            @endif
        </div>
        <p class="sr-only" data-payment-loading>{{ __('checkout.card.loading') }}</p>
    </div>
</div>
