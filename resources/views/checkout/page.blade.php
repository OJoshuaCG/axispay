{{--
    The payment page of a link (plan 11.2, 11.3, DESIGN.md, ADR-0051), every
    state. Presentation only: CheckoutPageBuilder decides the state and the
    data. Layout: one column on the page up to 767px; a hairline card
    centred from 768px; from 1024px the summary (left, sticky) and the form
    side by side. DOM order: header, summary, form, footer.
--}}
@use('App\Modules\Checkout\Enums\CheckoutState')
@use('App\Modules\Shared\Money\MoneyDisplay')
@php
    /** @var \App\Modules\Checkout\Data\CheckoutPage $page */

    $amountText = MoneyDisplay::format($page->money);
    $merchant = $page->merchant;
    $fonts = app(\App\Modules\Checkout\Services\CheckoutFonts::class);

    [$heading, $variant, $icon] = match ($page->state) {
        CheckoutState::Active => [__('checkout.title.pay', ['merchant' => $merchant]), null, null],
        CheckoutState::Processing => [__('checkout.states.processing.heading'), 'info', 'arrow-path'],
        CheckoutState::Paid => $page->paidInThisSession
            ? [__('checkout.states.paid.heading'), 'success', 'check-circle']
            : [__('checkout.states.already_paid.heading'), 'success', 'check-circle'],
        CheckoutState::Expired => [__('checkout.states.expired.heading'), 'neutral', 'calendar'],
        CheckoutState::Canceled => [__('checkout.states.canceled.heading'), 'neutral', 'no-symbol'],
        CheckoutState::Unavailable => [__('checkout.states.blocked.heading'), 'warning', 'exclamation-triangle'],
    };
@endphp

<x-layouts.checkout
    :title="$heading.' · '.$merchant"
    :load-stripe="$page->state === CheckoutState::Active && $page->client !== null"
    :sandbox="$page->sandbox"
    :numeric-font="$fonts->numericPreloadUrl()"
>
    <div class="mx-auto w-full max-w-narrow md:rounded-xl md:border md:border-line md:p-inset-lg lg:grid lg:max-w-checkout lg:grid-cols-5 lg:gap-x-stack-xl">
        <x-checkout.merchant-header :merchant="$merchant" class="lg:col-span-5" />

        <x-checkout.order-summary
            :merchant="$merchant"
            :description="$page->description"
            :money="$page->money"
            :expires-at="$page->expiresSoonAt"
            :show-amount="$page->showsAmount()"
            class="pb-stack-lg lg:sticky lg:top-stack-xl lg:col-span-2 lg:self-start"
        />

        <div class="flex flex-col gap-stack-lg lg:col-span-3" data-checkout-root>
            <div id="checkout-live" role="status" aria-live="polite" class="sr-only"></div>

            @if ($page->state === CheckoutState::Active && $page->client !== null)
                <h1 class="sr-only" tabindex="-1">{{ $heading }}</h1>

                <form id="checkout-form" class="flex flex-col gap-stack-lg" novalidate data-checkout-form>
                    <x-alert variant="error" data-checkout-alert="error" tabindex="-1" hidden><span data-alert-text></span></x-alert>
                    <x-alert variant="warning" data-checkout-alert="warning" tabindex="-1" hidden><span data-alert-text></span></x-alert>

                    @if ($page->collectsPayerData())
                        @include('checkout.payer-fields', ['page' => $page])
                    @endif

                    <x-checkout.payment-element :sandbox="$page->sandbox" />

                    <x-checkout.turnstile />

                    <div class="flex flex-col gap-stack-sm">
                        <x-button
                            type="submit"
                            size="lg"
                            icon="lock-closed"
                            class="w-full"
                            aria-disabled="true"
                            data-pay-button
                            :loading-label="__('checkout.processing_payment')"
                        >{!! __('checkout.pay_amount', ['amount' => '<span class="amount">'.e($amountText).'</span>']) !!}</x-button>

                        <p class="flex items-start justify-center gap-1.5 text-center text-sm text-fg-secondary">
                            <x-icon name="lock-closed" variant="mini" size="sm" class="mt-0.5 shrink-0" />
                            <span class="break-words">{{ __('checkout.trust') }}</span>
                        </p>
                    </div>
                </form>

                {{-- Panels the script shows in place of the form (copy rendered here, never in JS). --}}
                <template data-template="rejected">
                    <x-checkout.status-panel variant="error" icon="x-circle" :heading="__('checkout.states.rejected.heading', ['merchant' => $merchant])">
                        <p data-rejected-intro hidden>{{ __('checkout.states.rejected.message_from', ['merchant' => $merchant]) }}</p>
                        <blockquote data-rejected-message hidden class="border-s-4 border-line-strong ps-inset-md whitespace-pre-line text-fg"></blockquote>
                        <p data-rejected-fallback>{{ __('checkout.states.rejected.fallback', ['merchant' => $merchant]) }}</p>
                        <x-alert variant="info" role="note">{{ __('checkout.states.voided') }}</x-alert>
                    </x-checkout.status-panel>
                </template>

                <div hidden
                     data-phase-three-ds="{{ __('checkout.phase.three_ds') }}"
                     data-phase-validating="{{ __('checkout.phase.validating') }}"
                     data-processing="{{ __('checkout.processing_payment') }}"
                     data-turnstile-message="{{ __('checkout.messages.turnstile') }}"
                     data-error-message="{{ __('checkout.messages.error') }}"
                     data-security-unavailable="{{ __('checkout.messages.security_unavailable') }}"
                     data-fix-fields="{{ __('checkout.messages.fix_fields') }}"
                     data-auth-failed="{{ __('checkout.messages.authentication_failed') }}"
                     data-paused-message="{{ $page->client['pausedMinutes'] !== null ? __('checkout.messages.rate_limited', ['minutes' => $page->client['pausedMinutes']]) : '' }}"
                     data-checkout-strings></div>

                {{-- Probe for Stripe's Appearance API: colors read from real utilities (DESIGN.md). --}}
                <div aria-hidden="true" class="pointer-events-none invisible absolute size-0 overflow-hidden" data-appearance-probe>
                    <span data-probe="primary" class="text-primary"></span>
                    <span data-probe="background" class="text-page"></span>
                    <span data-probe="text" class="text-fg"></span>
                    <span data-probe="textSecondary" class="text-fg-secondary"></span>
                    <span data-probe="textPlaceholder" class="text-fg-muted"></span>
                    <span data-probe="danger" class="text-error"></span>
                    <span data-probe="lineStrong" class="text-line-strong"></span>
                    <span data-probe="focus" class="text-focus-ring"></span>
                </div>

                <script type="application/json" id="checkout-config" @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>@json($page->client)</script>
            @else
                <x-checkout.status-panel :variant="$variant" :icon="$icon" :heading="$heading" focus
                                         :data-poll="$page->state === CheckoutState::Processing ? json_encode(['status' => route('checkout.status', ['token' => $page->token], false), 'intervalMs' => config()->integer('axispay.checkout.poll_interval_seconds') * 1000, 'maxMs' => config()->integer('axispay.checkout.poll_max_seconds') * 1000]) : null">
                    @switch($page->state)
                        @case(CheckoutState::Processing)
                            <p data-poll-body>{{ $page->phase === \App\Modules\Checkout\Enums\CheckoutPhase::Validating ? __('checkout.phase.validating') : __('checkout.states.processing.body') }}</p>
                            <div data-poll-timeout hidden class="flex flex-col gap-stack-sm">
                                <x-alert variant="warning" :title="__('checkout.states.timeout.heading')">{{ __('checkout.states.timeout.body') }}</x-alert>
                                <x-button variant="secondary" data-poll-retry>{{ __('checkout.states.timeout.action') }}</x-button>
                            </div>
                            @break

                        @case(CheckoutState::Paid)
                            @if ($page->paidInThisSession)
                                <p><x-amount :value="$page->money->minorAmount" :currency="$page->money->currency->value" minor :signed="false" class="text-xl font-semibold" /></p>
                            @endif
                            @if ($page->paidAt)
                                <p class="text-fg-secondary"><time datetime="{{ $page->paidAt->toIso8601String() }}">{{ __('checkout.states.paid.paid_on', ['date' => $page->paidAt->isoFormat('LLL').' '.$page->paidAt->format('T')]) }}</time></p>
                            @endif
                            @break

                        @case(CheckoutState::Expired)
                            <p>{{ __('checkout.states.expired.body', ['merchant' => $merchant]) }}</p>
                            @if ($page->supportEmail)
                                <p><a href="mailto:{{ $page->supportEmail }}" class="inline-flex min-h-touch items-center break-all text-link underline">{{ __('checkout.contact', ['email' => $page->supportEmail]) }}</a></p>
                            @endif
                            @break

                        @case(CheckoutState::Canceled)
                            <p>{{ __('checkout.states.canceled.body') }}</p>
                            @break

                        @default
                            <p>{{ __('checkout.states.blocked.body', ['merchant' => $merchant]) }}</p>
                            @if ($page->supportEmail)
                                <p><a href="mailto:{{ $page->supportEmail }}" class="inline-flex min-h-touch items-center break-all text-link underline">{{ __('checkout.contact', ['email' => $page->supportEmail]) }}</a></p>
                            @endif
                    @endswitch

                    @if ($page->state === CheckoutState::Paid && $page->returnUrl)
                        <x-slot:actions>
                            <x-button :href="$page->returnUrl" rel="noopener noreferrer" size="lg" class="w-full sm:w-auto">{{ __('checkout.states.paid.return', ['merchant' => $merchant]) }}</x-button>
                        </x-slot:actions>
                    @endif
                </x-checkout.status-panel>
            @endif
        </div>
    </div>

    <x-slot:footer>
        <x-checkout.footer :privacy-url="$page->privacyUrl" :support-email="$page->supportEmail" :show-privacy="$page->collectsPayerData()" />
    </x-slot:footer>
</x-layouts.checkout>
