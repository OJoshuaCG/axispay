{{--
    Mount point of the gateway's card form (Stripe Payment Element, or the
    sandbox stub). A skeleton of three bars reserves the space (no layout
    shift, no shimmer) until the element reports `ready`; the script
    removes it. `sandboxLabels` (JSON) carries the stub's translated copy.

    Props: sandbox (bool)
--}}
@props(['sandbox' => false])

<div {{ $attributes->class('flex flex-col gap-stack-sm') }}>
    <p id="payment-element-label" class="text-sm font-medium text-fg">{{ __('checkout.card.heading') }}</p>

    <div class="relative min-h-60" aria-labelledby="payment-element-label" role="group">
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

        <div data-payment-skeleton class="absolute inset-0 flex flex-col gap-stack-md" aria-hidden="true">
            @foreach (range(1, 3) as $bar)
                <div class="flex flex-col gap-stack-xs">
                    <div class="h-4 w-24 rounded-md bg-surface-alt"></div>
                    <div class="h-11 w-full rounded-md bg-surface-alt"></div>
                </div>
            @endforeach
        </div>
        <p class="sr-only" data-payment-loading>{{ __('checkout.card.loading') }}</p>
    </div>
</div>
