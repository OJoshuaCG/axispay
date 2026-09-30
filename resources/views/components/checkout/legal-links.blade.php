{{--
    The merchant's legal documents under the order summary (ADR-0056):
    "Privacy notice · Terms" for whichever the merchant published, in every
    state of the page. A text opens in a native modal <dialog> (labelled by
    its heading; Escape, the close button or the backdrop close it; focus
    returns to the link); its content is rendered here, so the dialog needs
    no request. Without JavaScript the link opens the document's own page.
    A link document opens in a new tab.

    The dialog (ADR-0056 part C): a full-screen sheet below 640px; from 640px
    a centered panel, max-w-narrow and at most 5/6 of the viewport's height.
    Header (the merchant, small, and the document's h2), a scrollable body
    (`legal-prose`), a footer with the close button. On opening, focus goes
    to the h2 (tabindex -1): for long content the reader starts at the top
    of the text, not on the button at its end (WAI-ARIA APG, dialog pattern).
    `open:flex`, never a plain `flex` (it would show a closed dialog). The
    page does not scroll behind it (base.css), and it fades in unless the
    payer asked for reduced motion (`dialog-enter`, components.css).

    Props:
        documents  array<string, App\Modules\Legal\Data\LegalDocument> (kind => document)
        token      the payment link's public token
        merchant   display name, shown small above the document's title (optional)
--}}
@props(['documents' => [], 'token', 'merchant' => null])

@if ($documents !== [])
    @php($markdown = app(\App\Modules\Legal\Services\LegalMarkdown::class))

    <nav {{ $attributes->class('flex flex-wrap items-center gap-x-2 text-sm text-fg-secondary') }} aria-label="{{ __('checkout.legal.nav') }}">
        @foreach (array_values($documents) as $index => $document)
            @if ($index > 0)
                <span aria-hidden="true">·</span>
            @endif
            <x-checkout.legal-link :document="$document" :token="$token" :label="$document->kind->checkoutLabel()" class="inline-flex min-h-touch items-center text-fg-secondary underline" />
        @endforeach
    </nav>

    @foreach ($documents as $document)
        @if ($document->isText())
            @php($id = 'legal-'.$document->kind->value)
            <dialog id="{{ $id }}" data-legal-dialog aria-labelledby="{{ $id }}-title"
                    class="dialog-enter m-0 h-dvh max-h-none w-full max-w-none flex-col overflow-hidden bg-page p-0 pt-safe-top pb-safe-bottom text-fg open:flex backdrop:bg-neutral-900/60 sm:m-auto sm:h-auto sm:max-h-5/6 sm:max-w-narrow sm:rounded-xl sm:border sm:border-line sm:py-0 sm:shadow-xl">
                <div class="flex flex-col gap-stack-xs border-b border-line px-inset-md py-stack-md sm:px-inset-lg">
                    @if (filled($merchant))
                        <p class="text-sm break-words text-fg-secondary">{{ $merchant }}</p>
                    @endif
                    <h2 id="{{ $id }}-title" tabindex="-1" autofocus class="text-xl font-semibold break-words text-fg focus-visible:outline-none">{{ $document->kind->checkoutTitle() }}</h2>
                </div>
                <div class="legal-prose min-h-0 flex-1 overflow-y-auto px-inset-md py-stack-md sm:px-inset-lg" tabindex="0" role="region" aria-labelledby="{{ $id }}-title">
                    {{ $markdown->render((string) $document->body, 3) }}
                </div>
                <form method="dialog" class="flex justify-end border-t border-line px-inset-md py-stack-sm sm:px-inset-lg">
                    <x-button type="submit" variant="secondary" icon="x-mark" class="w-full sm:w-auto">{{ __('checkout.legal.close') }}</x-button>
                </form>
            </dialog>
        @endif
    @endforeach
@endif
