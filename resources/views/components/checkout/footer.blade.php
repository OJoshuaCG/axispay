{{--
    Checkout footer (ADR-0038, ADR-0056 part C), on every state, two tiers
    and no rule above it:
        1. the merchant's help line, "Questions about your payment? Email
           {email}" (only when the merchant has a support e-mail);
        2. the platform tier: "Powered by" the platform (logo and/or name,
           ADR-0053) · "Processed by Stripe".
    Phones to tablets: two centered lines; from 1024px: one row, the help
    line at the start and the platform tier at the end.

    "Powered by" links to the platform's legal page (`/legal`, ADR-0056) in
    a new tab when the platform published a privacy notice or terms (the
    payer never leaves the payment); otherwise it is plain text. The
    merchant's own legal documents sit under the order summary, not here.
    Links are 44px tall targets.

    Props: supportEmail (nullable)
--}}
@props(['supportEmail' => null])

@php
    // The translated sentence is escaped; only the brand markup replaces its placeholder.
    $platformMarker = "\u{E000}";
    $poweredBy = str_replace(e($platformMarker), view('components.checkout.platform-brand')->render(), e(__('checkout.footer.powered_by', ['platform' => $platformMarker])));
    $platformLegal = app(\App\Modules\Legal\Services\PlatformLegalDocuments::class)->hasAny();
@endphp

<footer {{ $attributes->class('mx-auto flex w-full max-w-narrow flex-col items-center gap-stack-xs py-stack-lg text-center lg:max-w-checkout lg:flex-row lg:justify-between lg:text-start') }}>
    @if ($supportEmail)
        <p class="text-sm text-fg-secondary">
            {{-- The address may break anywhere (a long one must not overflow 320px); the sentence wraps at spaces. --}}
            <a href="mailto:{{ $supportEmail }}" class="inline-flex min-h-touch items-center text-fg-secondary underline"><span class="min-w-0">{!! __('checkout.footer.help', ['email' => '<span class="break-all">'.e($supportEmail).'</span>']) !!}</span></a>
        </p>
    @endif

    <p class="flex flex-wrap items-center justify-center gap-x-stack-sm text-xs text-fg-secondary lg:ms-auto">
        @if ($platformLegal)
            <a href="{{ route('checkout.platform-legal', [], false) }}" class="inline-flex min-h-touch items-center gap-1 text-fg-secondary underline hover:text-fg" target="_blank" rel="noopener noreferrer" data-platform-legal>{!! $poweredBy !!}<span class="sr-only"> {{ __('checkout.legal.new_tab') }}</span></a>
        @else
            <span class="inline-flex min-h-touch items-center gap-1">{!! $poweredBy !!}</span>
        @endif
        <span aria-hidden="true" class="text-fg-muted">·</span>
        <span>{{ __('checkout.footer.processed_by') }}</span>
    </p>
</footer>
