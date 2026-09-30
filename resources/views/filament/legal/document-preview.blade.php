{{--
    Legal page of both panels (ADR-0056): the state of one document. Not
    set (with the tenant's privacy warning), a link (opens in a new tab), or
    a text previewed as payers see it (legal-prose, the same rendering as the
    pay host).

    Props: document (App\Modules\Legal\Data\LegalDocument|null),
           html (HtmlString|null: the rendered text), warning (string|null)
--}}
@if ($document === null)
    <div class="flex flex-col gap-stack-sm">
        <p class="text-sm text-fg-secondary">{{ __('legal.status.not_set') }}</p>
        @if ($warning)
            <p class="flex items-start gap-1.5 text-sm text-fg" data-legal-warning>
                <x-icon name="exclamation-triangle" variant="mini" size="sm" class="mt-0.5 shrink-0 text-warning" />
                <span>{{ $warning }}</span>
            </p>
        @endif
    </div>
@elseif ($document->isUrl())
    <p class="flex flex-col gap-stack-xs text-sm text-fg-secondary">
        <span>{{ __('legal.status.link') }}</span>
        <a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="break-all text-link underline">{{ $document->url }}</a>
    </p>
@else
    <div class="flex flex-col gap-stack-sm">
        <p class="text-sm text-fg-secondary">{{ __('legal.status.text') }}</p>
        <div class="legal-prose max-h-96 overflow-y-auto rounded-lg border border-line p-inset-md text-sm" tabindex="0" role="region" aria-label="{{ $document->kind->label() }}">
            {{ $html }}
        </div>
    </div>
@endif
