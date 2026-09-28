{{--
    The pay host's request limit was exceeded on a page load (ADR-0051,
    CheckoutRateLimits). Answered with 429 and Retry-After. No merchant
    information.
--}}
<x-layouts.checkout :title="__('checkout.title.too_many_requests').' · '.\App\Modules\Shared\Support\Brand::displayName()">
    <div class="mx-auto w-full max-w-narrow md:rounded-xl md:border md:border-line md:p-inset-lg">
        <header class="flex flex-wrap items-center justify-end gap-x-4 gap-y-stack-sm pb-stack-lg">
            <x-language-switcher />
        </header>

        <x-checkout.status-panel variant="neutral" icon="clock" :heading="__('checkout.states.too_many_requests.heading')" focus>
            <p>{{ __('checkout.states.too_many_requests.body') }}</p>
        </x-checkout.status-panel>
    </div>

    <x-slot:footer>
        <x-checkout.footer />
    </x-slot:footer>
</x-layouts.checkout>
