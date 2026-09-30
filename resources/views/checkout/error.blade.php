{{--
    Error page of the pay host (ADR-0051): the payer never sees the
    framework's own pages. Same layout and footer as the checkout, no
    merchant information (the link may be unknown). Props: status (419,
    500, 503), rendered by CheckoutErrorPages.
--}}
@props(['status'])

@php
    $icon = match ((int) $status) {
        419 => 'clock',
        503 => 'wrench-screwdriver',
        default => 'exclamation-triangle',
    };
@endphp

<x-layouts.checkout :title="__('checkout.error_pages.'.$status.'.heading').' · '.\App\Modules\Shared\Support\Brand::displayName()">
    <div class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg">
        {{-- No merchant here: the site controls only (language and theme). --}}
        <x-checkout.merchant-header />

        <x-checkout.card>
            <x-checkout.status-panel variant="neutral" :icon="$icon" :heading="__('checkout.error_pages.'.$status.'.heading')" focus>
                <p>{{ __('checkout.error_pages.'.$status.'.body') }}</p>
            </x-checkout.status-panel>
        </x-checkout.card>
    </div>

    <x-slot:footer>
        <x-checkout.footer />
    </x-slot:footer>
</x-layouts.checkout>
