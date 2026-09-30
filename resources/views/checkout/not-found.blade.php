{{--
    Invalid or unknown link token (plan 11.2): the same page, with no
    merchant information, for every invalid token. Answered with 404.
--}}
<x-layouts.checkout :title="__('checkout.title.not_found').' · '.\App\Modules\Shared\Support\Brand::displayName()">
    <div class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg">
        {{-- No merchant here: the site controls only (language and theme). --}}
        <x-checkout.merchant-header />

        <x-checkout.card>
            <x-checkout.status-panel variant="neutral" icon="link-slash" :heading="__('checkout.states.not_found.heading')" focus>
                <p>{{ __('checkout.states.not_found.body') }}</p>
            </x-checkout.status-panel>
        </x-checkout.card>
    </div>

    <x-slot:footer>
        <x-checkout.footer />
    </x-slot:footer>
</x-layouts.checkout>
