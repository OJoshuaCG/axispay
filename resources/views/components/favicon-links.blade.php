{{--
    Favicon tags (ADR-0053), in every layout: both panels (PanelDefaults,
    HEAD_END), the checkout layout (payment page and pay-host error pages)
    and the plain Blade layout. The platform favicon when a superadmin
    uploaded one (versioned, same-origin URLs); otherwise the default
    /favicon.ico shipped with the application.
--}}
@php
    $brand = app(\App\Modules\Branding\Services\PlatformBrand::class);
@endphp
@if ($brand->hasFavicon())
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $brand->faviconUrl(\App\Modules\Branding\Enums\FaviconSize::Tab) }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ $brand->faviconUrl(\App\Modules\Branding\Enums\FaviconSize::Android) }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $brand->faviconUrl(\App\Modules\Branding\Enums\FaviconSize::AppleTouch) }}">
@else
    <link rel="icon" href="{{ \App\Modules\Branding\Services\PlatformBrand::DEFAULT_FAVICON }}" sizes="any">
@endif
