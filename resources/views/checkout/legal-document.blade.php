{{--
    The merchant's privacy notice or terms on their own page (ADR-0056): what
    the checkout's legal dialog shows, for payers without JavaScript or who
    open the link in a new tab. A text is rendered here (headings from h2); a
    link document shows where the merchant publishes it. Presentation only:
    CheckoutLegalController resolves the link and the document.

    Data: merchant, merchantLogo (App\Modules\Checkout\Data\MerchantLogo|null),
          supportEmail (nullable), token, document
          (App\Modules\Legal\Data\LegalDocument), html (HtmlString|null)
--}}
@php
    /** @var \App\Modules\Legal\Data\LegalDocument $document */
    $title = $document->kind->checkoutTitle();
@endphp

<x-layouts.checkout :title="$title.' · '.$merchant">
    <div class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg">
        <x-checkout.merchant-header :merchant="$merchant" :logo="$merchantLogo" />

        <x-checkout.card as="div" class="flex flex-col gap-stack-md">
            <p>
                <a href="{{ route('checkout.show', ['token' => $token], false) }}" class="inline-flex min-h-touch items-center gap-1.5 text-link underline">
                    <x-icon name="arrow-left" variant="mini" size="sm" class="shrink-0 rtl:rotate-180" />
                    <span>{{ __('checkout.legal.back') }}</span>
                </a>
            </p>

            <article class="flex flex-col gap-stack-md" aria-labelledby="legal-title">
                <h1 id="legal-title" class="text-2xl font-semibold break-words text-fg">{{ $title }}</h1>

                @if ($document->isText())
                    <div class="legal-prose">{{ $html }}</div>
                @else
                    <p class="text-fg-secondary">{{ __('checkout.legal.external', ['merchant' => $merchant]) }}</p>
                    <p>
                        <a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-touch items-center break-all text-link underline">
                            {{ __('checkout.legal.open_external') }}<span class="sr-only"> {{ __('checkout.legal.new_tab') }}</span>
                        </a>
                    </p>
                @endif
            </article>
        </x-checkout.card>
    </div>

    <x-slot:footer>
        <x-checkout.footer :support-email="$supportEmail" />
    </x-slot:footer>
</x-layouts.checkout>
