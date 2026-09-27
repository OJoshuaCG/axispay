{{--
    Invalid or unknown link token (plan 11.2): the same page, with no
    merchant information, for every invalid token. Answered with 404.
--}}
<x-layouts.checkout :title="__('checkout.title.not_found').' · '.\App\Modules\Shared\Support\Brand::displayName()">
    <div class="mx-auto w-full max-w-narrow md:rounded-xl md:border md:border-line md:p-inset-lg">
        <header class="flex flex-wrap items-center justify-end gap-x-4 gap-y-stack-sm pb-stack-lg">
            <x-language-switcher />
        </header>

        <x-checkout.status-panel variant="neutral" icon="link-slash" :heading="__('checkout.states.not_found.heading')" focus>
            <p>{{ __('checkout.states.not_found.body') }}</p>
        </x-checkout.status-panel>
    </div>

    <x-slot:footer>
        <x-checkout.footer />
    </x-slot:footer>
</x-layouts.checkout>
