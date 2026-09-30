{{--
    The pay host's request limit was exceeded on a page load (ADR-0051,
    CheckoutRateLimits). Answered with 429 and Retry-After. No merchant
    information.
--}}
<x-layouts.checkout :title="__('checkout.title.too_many_requests').' · '.\App\Modules\Shared\Support\Brand::displayName()">
    <div class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg">
        {{-- No merchant here: the site controls only (language and theme). --}}
        <x-checkout.merchant-header />

        <x-checkout.card>
            <x-checkout.status-panel variant="neutral" icon="clock" :heading="__('checkout.states.too_many_requests.heading')" focus>
                <p>{{ __('checkout.states.too_many_requests.body') }}</p>
            </x-checkout.status-panel>
        </x-checkout.card>
    </div>

    <x-slot:footer>
        <x-checkout.footer />
    </x-slot:footer>
</x-layouts.checkout>
