{{--
    The status panel of a checkout state that is not the form (processing,
    paid, expired, canceled, blocked), included by checkout/page.blade.php
    with its variables: in the right card while processing, first in the
    single card otherwise. The poll attributes and copy are unchanged.
--}}
@use('App\Modules\Checkout\Enums\CheckoutState')
<x-checkout.status-panel :variant="$variant" :icon="$icon" :heading="$heading" focus
                         :data-poll="$page->state === CheckoutState::Processing ? json_encode(['status' => route('checkout.status', ['token' => $page->token], false), 'intervalMs' => config()->integer('axispay.checkout.poll_interval_seconds') * 1000, 'maxMs' => config()->integer('axispay.checkout.poll_max_seconds') * 1000]) : null">
    @switch($page->state)
        @case(CheckoutState::Processing)
            <p data-poll-body>{{ $page->phase === \App\Modules\Checkout\Enums\CheckoutPhase::Validating ? __('checkout.phase.validating') : __('checkout.states.processing.body') }}</p>
            <div data-poll-timeout hidden class="flex flex-col gap-stack-sm">
                <x-alert variant="warning" tabindex="-1" :title="__('checkout.states.timeout.heading')">{{ __('checkout.states.timeout.body') }}</x-alert>
                <x-button variant="secondary" data-poll-retry>{{ __('checkout.states.timeout.action') }}</x-button>
            </div>
            @break

        @case(CheckoutState::Paid)
            @if ($page->paidInThisSession)
                <p><x-amount :value="$page->money->minorAmount" :currency="$page->money->currency->value" minor :signed="false" class="text-xl font-semibold" /></p>
            @endif
            @if ($page->autoRedirectSeconds !== null && $page->returnUrl)
                {{-- ADR-0064: shown by the script only; the countdown never promises a redirect that cannot happen. --}}
                <p class="flex flex-wrap items-baseline gap-x-stack-sm text-fg-secondary" hidden data-redirect-countdown data-auto-redirect="{{ $page->autoRedirectSeconds }}" data-redirect-url="{{ $page->returnUrl }}">
                    <span>{!! __('checkout.redirect.countdown', ['merchant' => e($merchant), 'seconds' => '<span class="font-numeric" data-redirect-seconds>'.$page->autoRedirectSeconds.'</span>']) !!}</span>
                    <button type="button" class="inline-flex min-h-touch items-center text-link underline" data-redirect-stop>{{ __('checkout.redirect.stop') }}</button>
                </p>
            @endif
            @if ($page->paidAt)
                <p class="text-fg-secondary">{!! __('checkout.states.paid.paid_on', ['date' => '<time datetime="'.e($page->paidAt->toIso8601String()).'">'.e($page->paidAt->isoFormat('LLL').' '.$page->paidAt->format('T')).'</time>']) !!}</p>
            @endif
            @break

        @case(CheckoutState::Expired)
            <p>{{ __('checkout.states.expired.body', ['merchant' => $merchant]) }}</p>
            @if ($page->supportEmail)
                <p><a href="mailto:{{ $page->supportEmail }}" class="inline-flex min-h-touch items-center break-all text-link underline">{{ __('checkout.contact', ['email' => $page->supportEmail]) }}</a></p>
            @endif
            @break

        @case(CheckoutState::Canceled)
            {{-- The heading says it all. --}}
            @break

        @default
            <p>{{ __('checkout.states.blocked.body', ['merchant' => $merchant]) }}</p>
            @if ($page->supportEmail)
                <p><a href="mailto:{{ $page->supportEmail }}" class="inline-flex min-h-touch items-center break-all text-link underline">{{ __('checkout.contact', ['email' => $page->supportEmail]) }}</a></p>
            @endif
    @endswitch

    {{-- The way back to the merchant (ADR-0064): the signed return once paid, the plain URL once expired or canceled. --}}
    @if ($page->returnUrl && in_array($page->state, [CheckoutState::Paid, CheckoutState::Expired, CheckoutState::Canceled], true))
        <x-slot:actions>
            <x-button :href="$page->returnUrl" rel="noopener noreferrer" size="lg" class="w-full sm:w-auto">{{ __('checkout.states.paid.return', ['merchant' => $merchant]) }}</x-button>
        </x-slot:actions>
    @endif
</x-checkout.status-panel>
