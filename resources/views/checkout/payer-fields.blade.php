{{--
    Payer fields of the link (plan 19.1), labels above, "(optional)" in the
    label. Inputs are named payer[...] and read by the page script; errors
    come back from the server (x-checkout.field-error).
--}}
@php
    /** @var \App\Modules\Checkout\Data\CheckoutPage $page */
    $countries = \App\Modules\PayerFields\Data\PayerCountries::options(app()->getLocale());
    $label = static fn (string $key, bool $required): string => $required ? __($key) : __('checkout.payer.optional_label', ['label' => __($key)]);
@endphp

<fieldset class="flex min-w-0 flex-col gap-stack-md">
    <legend class="pb-stack-sm text-base font-semibold text-fg">{{ __('checkout.payer.heading') }}</legend>

    @foreach ($page->payerFields as $field)
        @php $required = $field['required']; @endphp

        @switch($field['field'])
            @case('email')
                <div class="flex flex-col gap-stack-xs" data-field="email">
                    <x-input id="payer-email" name="payer[email]" type="email" autocomplete="email" :label="$label('checkout.payer.email', $required)" :required="$required" aria-describedby="payer-email-error" maxlength="254" />
                    <x-checkout.field-error id="payer-email-error" />
                </div>
                @break

            @case('full_name')
                <div class="flex flex-col gap-stack-xs" data-field="full_name">
                    <x-input id="payer-full_name" name="payer[full_name]" autocomplete="name" :label="$label('checkout.payer.full_name', $required)" :required="$required" aria-describedby="payer-full_name-error" maxlength="120" />
                    <x-checkout.field-error id="payer-full_name-error" />
                </div>
                @break

            @case('phone')
                <x-phone-input name="payer[phone]" country-name="payer[phone_country]" :countries="$countries" :label="$label('checkout.payer.phone', $required)" :required="$required" />
                @break

            @case('company_name')
                <div class="flex flex-col gap-stack-xs" data-field="company_name">
                    <x-input id="payer-company_name" name="payer[company_name]" autocomplete="organization" :label="$label('checkout.payer.company_name', $required)" :required="$required" aria-describedby="payer-company_name-error" maxlength="120" />
                    <x-checkout.field-error id="payer-company_name-error" />
                </div>
                @break

            @case('billing_address')
                <fieldset class="flex min-w-0 flex-col gap-stack-md" data-field="billing_address">
                    <legend class="pb-stack-xs text-sm font-medium text-fg">{{ $label('checkout.payer.address.legend', $required) }}</legend>

                    <div class="flex flex-col gap-stack-xs" data-field="billing_address.country">
                        <label for="payer-billing_address-country" class="text-sm font-medium text-fg">{{ __('checkout.payer.address.country') }}</label>
                        <select id="payer-billing_address-country" name="payer[billing_address][country]" autocomplete="country" aria-describedby="payer-billing_address-country-error"
                                class="min-h-touch w-full rounded-md border border-line-strong bg-page px-3 text-base text-fg hover:border-fg-secondary">
                            @foreach ($countries as $code => $countryName)
                                <option value="{{ $code }}" @selected($code === \App\Modules\PayerFields\Data\PayerCountries::DEFAULT)>{{ $countryName }}</option>
                            @endforeach
                        </select>
                        <x-checkout.field-error id="payer-billing_address-country-error" />
                    </div>

                    @foreach (['line1' => 'address-line1', 'line2' => 'address-line2', 'city' => 'address-level2', 'state' => 'address-level1', 'postal_code' => 'postal-code'] as $part => $autocomplete)
                        <div class="flex flex-col gap-stack-xs" data-field="billing_address.{{ $part }}">
                            <x-input
                                id="payer-billing_address-{{ $part }}"
                                name="payer[billing_address][{{ $part }}]"
                                :autocomplete="$autocomplete"
                                :label="$part === 'line2' ? __('checkout.payer.optional_label', ['label' => __('checkout.payer.address.line2')]) : __('checkout.payer.address.'.$part)"
                                :required="$required && $part !== 'line2'"
                                :inputmode="$part === 'postal_code' ? 'numeric' : null"
                                aria-describedby="payer-billing_address-{{ $part }}-error"
                                maxlength="120"
                            />
                            <x-checkout.field-error :id="'payer-billing_address-'.$part.'-error'" />
                        </div>
                    @endforeach
                </fieldset>
                @break

            @case('tax_id')
                <div class="flex flex-col gap-stack-xs" data-field="tax_id">
                    <x-input id="payer-tax_id" name="payer[tax_id]" autocomplete="off" :label="$label('checkout.payer.tax_id', $required)" :required="$required" aria-describedby="payer-tax_id-error" maxlength="20" class="uppercase" />
                    <x-checkout.field-error id="payer-tax_id-error" />
                </div>
                @break

            @case('notes')
                <div class="flex flex-col gap-stack-xs" data-field="notes">
                    <label for="payer-notes" class="text-sm font-medium text-fg">{{ $label('checkout.payer.notes', $required) }}</label>
                    <textarea id="payer-notes" name="payer[notes]" rows="3" maxlength="500" aria-describedby="payer-notes-error" @if ($required) required @endif
                              class="block w-full rounded-md border border-line-strong bg-page px-3 py-2 text-base text-fg placeholder:text-fg-muted hover:border-fg-secondary"></textarea>
                    <x-checkout.field-error id="payer-notes-error" />
                </div>
                @break
        @endswitch
    @endforeach

    {{-- The merchant's notice opens like the summary's link: a dialog for a text, a new tab for a link (ADR-0056). --}}
    <p class="text-sm text-fg-secondary break-words">
        @if ($notice = $page->privacyNotice())
            {!! __('checkout.payer.privacy', [
                'merchant' => e($page->merchant),
                'link' => view('components.checkout.legal-link', [
                    'document' => $notice,
                    'token' => $page->token,
                    'label' => __('checkout.payer.privacy_link'),
                    'attributes' => new \Illuminate\View\ComponentAttributeBag(['class' => 'text-link underline']),
                ])->render(),
            ]) !!}
        @else
            {{ __('checkout.payer.privacy_no_link', ['merchant' => $page->merchant]) }}
        @endif
    </p>
</fieldset>
