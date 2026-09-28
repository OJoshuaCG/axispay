{{--
    Cloudflare Turnstile slot (plan 11.7 rule 3), between the card form and
    the Pay button. Hidden until the server asks for it (after a decline);
    the script loads Turnstile's api.js then (with the CSP nonce) and renders
    the widget here: appearance "always", compact below 340px, flexible
    otherwise, page theme and language. The height is reserved while it
    loads.
--}}
<div {{ $attributes->class('flex flex-col gap-stack-xs') }} data-turnstile-slot hidden>
    <p class="text-sm font-medium text-fg" id="turnstile-label">{{ __('checkout.turnstile.label') }}</p>
    {{-- Turnstile's own sizes: flexible 65px high, compact 140px (the script marks `data-compact` below 340px wide). --}}
    <div data-turnstile class="min-h-17 data-compact:min-h-35" role="group" aria-labelledby="turnstile-label" tabindex="-1"></div>
</div>
