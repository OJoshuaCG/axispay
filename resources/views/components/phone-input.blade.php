{{--
    Phone field with a country selector (plan 19.1): a native <select> of
    calling codes (default +52 Mexico) and a national-number input
    (type=tel, autocomplete=tel-national, 16px). The server builds and
    validates the E.164 number (PayerFieldsValidator).

    Props:
        label, name (number input), countryName (select), country (selected ISO code)
        countries  array code => name (PayerCountries::options())
        value, error, hint, required
--}}
@props([
    'label',
    'name' => 'phone',
    'countryName' => 'phone_country',
    'country' => 'MX',
    'countries' => [],
    'value' => null,
    'error' => null,
    'hint' => null,
    'required' => false,
])

@php
    $id = str_replace(['[', ']', '.'], ['-', '', '-'], $name);
    $countryId = $id.'-country';
    $errorId = $id.'-error';
    $hintId = filled($hint) ? $id.'-hint' : null;
    $codes = \App\Modules\PayerFields\Data\PayerCountries::CALLING_CODES;
@endphp

<div {{ $attributes->class('flex min-w-0 flex-col gap-stack-xs') }} data-field="phone">
    <label for="{{ $id }}" class="text-sm font-medium text-fg">
        {{ $label }}
        @if ($required)
            <span class="text-error" aria-hidden="true">*</span>
        @endif
    </label>

    <div class="flex w-full gap-stack-sm">
        <label for="{{ $countryId }}" class="sr-only">{{ __('checkout.payer.phone_country') }}</label>
        <select
            id="{{ $countryId }}"
            name="{{ $countryName }}"
            autocomplete="tel-country-code"
            class="min-h-touch w-28 shrink-0 rounded-md border border-line-strong bg-page px-2 text-base text-fg hover:border-fg-secondary"
        >
            @foreach ($countries as $code => $countryLabel)
                {{-- `label` (ISO code + calling code, e.g. "MX +52") is what the select shows, so it fits next to the number at 320px; options are ordered by country name in the payer's language. --}}
                <option value="{{ $code }}" label="{{ $code }} +{{ $codes[$code] ?? '' }}" @selected($code === $country)>{{ $countryLabel }} (+{{ $codes[$code] ?? '' }})</option>
            @endforeach
        </select>

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="tel"
            inputmode="tel"
            autocomplete="tel-national"
            @if ($value !== null) value="{{ $value }}" @endif
            @if ($required) required @endif
            aria-describedby="{{ trim(($hintId ?? '').' '.$errorId) }}"
            @if ($error) aria-invalid="true" @endif
            @class([
                'block min-h-touch w-full min-w-0 rounded-md border bg-page px-3 text-base text-fg placeholder:text-fg-muted',
                'border-line-strong hover:border-fg-secondary' => ! $error,
                'border-error' => (bool) $error,
            ])
        />
    </div>

    @if ($hintId)
        <p id="{{ $hintId }}" class="text-sm break-words text-fg-secondary">{{ $hint }}</p>
    @endif

    <p id="{{ $errorId }}" data-field-error @class(['flex items-start gap-1.5 text-sm text-error', 'hidden' => ! $error])>
        <x-icon name="exclamation-circle" variant="mini" size="sm" class="mt-0.5" />
        <span class="min-w-0 break-words" data-field-error-text>{{ $error }}</span>
    </p>
</div>
