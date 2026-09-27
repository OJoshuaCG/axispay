{{--
    Checkout footer (ADR-0038), on every state: "Powered by" the platform,
    "Processed by Stripe", the merchant's privacy notice when the page
    collects payer data, and the merchant's support e-mail. Links are 44px
    tall targets.

    Props: privacyUrl, supportEmail (both nullable), showPrivacy (bool)
--}}
@props(['privacyUrl' => null, 'supportEmail' => null, 'showPrivacy' => false])

<footer {{ $attributes->class('mx-auto flex w-full max-w-narrow flex-wrap items-center justify-center gap-x-4 gap-y-stack-xs border-t border-line py-stack-md text-center text-sm text-fg-secondary lg:max-w-checkout') }}>
    <span>{{ __('checkout.footer.powered_by', ['platform' => \App\Modules\Shared\Support\Brand::displayName()]) }}</span>
    <span>{{ __('checkout.footer.processed_by') }}</span>
    @if ($showPrivacy && $privacyUrl)
        <a href="{{ $privacyUrl }}" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-touch items-center text-fg-secondary underline">{{ __('checkout.footer.privacy') }}</a>
    @endif
    @if ($supportEmail)
        <a href="mailto:{{ $supportEmail }}" class="inline-flex min-h-touch items-center break-all text-fg-secondary underline">{{ __('checkout.footer.support', ['email' => $supportEmail]) }}</a>
    @endif
</footer>
