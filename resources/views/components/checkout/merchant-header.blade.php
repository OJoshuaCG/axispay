{{--
    Checkout header (ADR-0038, ADR-0056 parts B and C): row 1, the site
    controls at the end (language and theme, <x-site-controls>, icon-only
    theme options at every width; their labels stay the accessible names and
    tooltips); row 2, the merchant's logo centered and large, with the
    merchant's name as its `alt`; without a logo, the merchant's name as
    centered text (never truncated). Pages with no merchant (the 404, the
    error pages, /legal) pass merchant null: the controls only.

    Dark theme: the dark variant replaces the light one (`dark:hidden` /
    `hidden dark:block`; both carry the same alt, only one is displayed).
    Without a dark variant the light logo sits on a light plate
    (`bg-logo-plate`: transparent in light, neutral-50 in dark), so a logo
    drawn for white backgrounds stays readable.

    Props: merchant (display name, or null for the controls only),
           logo (App\Modules\Checkout\Data\MerchantLogo|null)
--}}
@props(['merchant' => null, 'logo' => null])

@php
    /** @var \App\Modules\Checkout\Data\MerchantLogo|null $logo */
    $logoClass = 'h-auto w-auto max-h-20 max-w-56 object-contain sm:max-h-24 sm:max-w-72 lg:max-w-80';
@endphp

<header {{ $attributes->class('flex flex-col gap-stack-md') }}>
    <div class="flex justify-end">
        <x-site-controls />
    </div>

    @if ($logo !== null)
        <div class="flex justify-center" data-merchant-logo>
            @if ($logo->hasDarkVariant())
                <img src="{{ $logo->url }}" alt="{{ $merchant }}" width="{{ $logo->width }}" height="{{ $logo->height }}" class="{{ $logoClass }} dark:hidden" />
                <img src="{{ $logo->darkUrl }}" alt="{{ $merchant }}" width="{{ $logo->darkWidth ?? $logo->width }}" height="{{ $logo->darkHeight ?? $logo->height }}" class="{{ $logoClass }} hidden dark:block" />
            @else
                <img src="{{ $logo->url }}" alt="{{ $merchant }}" width="{{ $logo->width }}" height="{{ $logo->height }}" class="{{ $logoClass }} rounded-lg p-inset-sm bg-logo-plate" />
            @endif
        </div>
    @elseif ($merchant !== null)
        <p class="text-center text-2xl font-semibold break-words text-fg">{{ $merchant }}</p>
    @endif
</header>
