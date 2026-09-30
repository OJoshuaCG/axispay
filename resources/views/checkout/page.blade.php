{{--
    The payment page of a link (plan 11.2, 11.3, docs/frontend/checkout-design.md, ADR-0051), every
    state. Presentation only: CheckoutPageBuilder decides the state and the
    data. Layout (ADR-0056 part C): the header (site controls, the merchant's
    logo or name), then two cards on the canvas: the payment details (left)
    and the payer fields, card form and Pay (right); stacked up to 1023px,
    side by side (2/5 and 3/5) from 1024px. Processing keeps both cards (the
    right one shows the status). The other states are one card: the status,
    then the summary without its own card. DOM order: header, summary, form,
    footer.
--}}
@use('App\Modules\Checkout\Enums\CheckoutState')
@use('App\Modules\Shared\Money\MoneyDisplay')
@php
    /** @var \App\Modules\Checkout\Data\CheckoutPage $page */

    $amountText = MoneyDisplay::format($page->money);
    $merchant = $page->merchant;
    $fonts = app(\App\Modules\Checkout\Services\CheckoutFonts::class);

    // Two cards while the link takes (or is taking) the payment; one card for every outcome.
    $twoCards = ($page->state === CheckoutState::Active && $page->client !== null) || $page->state === CheckoutState::Processing;

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
    {{-- The page title names the merchant once: "Pay · Acme", never "Pay Acme · Acme". --}}
    :title="($page->state === CheckoutState::Active ? __('checkout.title.pay_short') : $heading).' · '.$merchant"
    :load-stripe="$page->state === CheckoutState::Active && $page->client !== null"
    :sandbox="$page->sandbox"
    :numeric-font="$fonts->numericPreloadUrl()"
>
    <div class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg lg:max-w-checkout">
        <x-checkout.merchant-header :merchant="$merchant" :logo="$page->merchantLogo" />

        @if ($twoCards)
            <div class="flex flex-col gap-stack-lg lg:grid lg:grid-cols-5 lg:items-start">
                <x-checkout.order-summary
                    :merchant="$merchant"
                    :description="$page->description"
                    :money="$page->money"
                    :expires-at="$page->expiresSoonAt"
                    :show-amount="$page->showsAmount()"
                    class="lg:col-span-2"
                >
                    {{-- ADR-0056: "Privacy notice · Terms", below the amount, in every state. --}}
                    <x-checkout.legal-links :documents="$page->legal" :token="$page->token" :merchant="$merchant" />
                </x-checkout.order-summary>

                <x-checkout.card as="div" class="flex flex-col gap-stack-lg lg:col-span-3" data-checkout-root>
                    <div id="checkout-live" role="status" aria-live="polite" class="sr-only"></div>

                    @if ($page->state === CheckoutState::Active && $page->client !== null)
                        <form id="checkout-form" class="checkout-fields flex flex-col gap-stack-lg" novalidate data-checkout-form>
                            {{-- Inside the form: a rejection replaces the form, and its panel brings the page's only h1. --}}
                            <h1 class="sr-only" tabindex="-1">{{ $heading }}</h1>

                            @if ($page->collectsPayerData())
                                @include('checkout.payer-fields', ['page' => $page])
                            @endif

                            {{-- The card part; a hairline separates it from the payer fields when there are any. --}}
                            <div @class(['flex flex-col gap-stack-md', 'border-t border-line pt-stack-lg' => $page->collectsPayerData()])>
                                {{-- Right before the card form (docs/frontend/checkout-design.md). role="group": the script moves focus to them, which announces them once. --}}
                                <x-alert variant="error" role="group" data-checkout-alert="error" tabindex="-1" hidden><span data-alert-text></span></x-alert>
                                <x-alert variant="warning" role="group" data-checkout-alert="warning" tabindex="-1" hidden><span data-alert-text></span></x-alert>

                                <x-checkout.payment-element :sandbox="$page->sandbox" />

                                {{-- Not even the slot while the bot check is switched off (ADR-0052). --}}
                                @if ($page->client['turnstile']['enabled'] ?? true)
                                    <x-checkout.turnstile />
                                @endif
                            </div>

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

                                <p class="flex items-start justify-start gap-1.5 text-start text-sm text-fg-secondary">
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
                             data-session-expired="{{ __('checkout.messages.session_expired') }}"
                             data-fix-fields="{{ __('checkout.messages.fix_fields') }}"
                             data-paused-message="{{ $page->client['pausedMinutes'] !== null ? trans_choice('checkout.messages.rate_limited', $page->client['pausedMinutes'], ['minutes' => $page->client['pausedMinutes']]) : '' }}"
                             data-checkout-strings></div>

                        {{-- Probe for Stripe's Appearance API: colors read from real utilities (docs/frontend/checkout-design.md). --}}
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
                        {{-- Processing: the status in the right card, the amount stays on the left. --}}
                        @include('checkout.page-status')
                    @endif
                </x-checkout.card>
            </div>
        @else
            {{-- Paid, expired, canceled, blocked: one card, the status first, then what the link was for. --}}
            <x-checkout.card class="mx-auto flex w-full max-w-narrow flex-col gap-stack-lg" data-checkout-root>
                <div id="checkout-live" role="status" aria-live="polite" class="sr-only"></div>

                @include('checkout.page-status')

                <div class="border-t border-line pt-stack-lg">
                    <x-checkout.order-summary
                        bare
                        :merchant="$merchant"
                        :description="$page->description"
                        :money="$page->money"
                        :expires-at="$page->expiresSoonAt"
                        :show-amount="$page->showsAmount()"
                    >
                        <x-checkout.legal-links :documents="$page->legal" :token="$page->token" :merchant="$merchant" />
                    </x-checkout.order-summary>
                </div>
            </x-checkout.card>
        @endif
    </div>

    <x-slot:footer>
        <x-checkout.footer :support-email="$page->supportEmail" />
    </x-slot:footer>
</x-layouts.checkout>
