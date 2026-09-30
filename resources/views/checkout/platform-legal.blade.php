{{--
    The platform's legal page on the pay host, `/legal` (ADR-0056): its
    privacy notice and terms in sections with the anchors #privacy and
    #terms, linked from the "Powered by" line of every payment page. A text
    is rendered here (headings from h3); a link document is a link out.
    No merchant information. Presentation only: CheckoutLegalController
    answers 404 while neither document is published.

    Data: documents (array<string, App\Modules\Legal\Data\LegalDocument>, kind => document),
          html (array<string, HtmlString>: the rendered texts, by kind)
--}}
@php
    $platform = \App\Modules\Shared\Support\Brand::displayName();
@endphp

<x-layouts.checkout :title="__('checkout.legal.platform_title').' · '.$platform">
    <div class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg">
        {{-- No merchant here: the site controls only (language and theme). --}}
        <x-checkout.merchant-header />

        <x-checkout.card as="div" class="flex flex-col gap-stack-lg">
            <div class="flex flex-col gap-stack-sm">
                <h1 class="text-2xl font-semibold break-words text-fg">{{ __('checkout.legal.platform_title') }}</h1>
                <p class="text-fg-secondary">{{ __('checkout.legal.platform_intro', ['platform' => $platform]) }}</p>
            </div>

            @if (count($documents) > 1)
                <nav aria-label="{{ __('checkout.legal.nav') }}">
                    <ul class="flex flex-wrap gap-x-4">
                        @foreach ($documents as $kind => $document)
                            <li><a href="#{{ $kind }}" class="inline-flex min-h-touch items-center text-link underline">{{ $document->kind->checkoutTitle() }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @foreach ($documents as $kind => $document)
                <section id="{{ $kind }}" class="flex scroll-mt-stack-lg flex-col gap-stack-md border-t border-line pt-stack-lg" aria-labelledby="{{ $kind }}-title">
                    <h2 id="{{ $kind }}-title" class="text-xl font-semibold break-words text-fg">{{ $document->kind->checkoutTitle() }}</h2>

                    @if ($document->isText())
                        <div class="legal-prose">{{ $html[$kind] ?? '' }}</div>
                    @else
                        <p class="text-fg-secondary">{{ __('checkout.legal.platform_external') }}</p>
                        <p>
                            <a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-touch items-center break-all text-link underline">
                                {{ __('checkout.legal.open_external') }}<span class="sr-only"> {{ __('checkout.legal.new_tab') }}</span>
                            </a>
                        </p>
                    @endif
                </section>
            @endforeach
        </x-checkout.card>
    </div>

    <x-slot:footer>
        <x-checkout.footer />
    </x-slot:footer>
</x-layouts.checkout>
