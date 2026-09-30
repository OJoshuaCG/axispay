{{--
    A link to one of the merchant's legal documents (ADR-0056). A text opens
    in the page's dialog (resources/js/checkout/legal-dialog.js) and, without
    JavaScript, on its own page under the link token; a link opens the
    merchant's page in a new tab, without referrer or opener.

    Props:
        document  App\Modules\Legal\Data\LegalDocument
        token     the payment link's public token
        label     the link text (plain text, escaped here)

    A prop rather than a slot, so a translated sentence can embed the link:
    view('components.checkout.legal-link', [..., 'attributes' => new ComponentAttributeBag([...])])->render().
--}}
@props(['document', 'token', 'label'])

@if ($document->isUrl())
    <a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" {{ $attributes }}>{{ $label }}<span class="sr-only"> {{ __('checkout.legal.new_tab') }}</span></a>
@else
    <a href="{{ route('checkout.legal', ['token' => $token, 'kind' => $document->kind->value], false) }}" data-legal-open="{{ $document->kind->value }}" aria-haspopup="dialog" {{ $attributes }}>{{ $label }}</a>
@endif
