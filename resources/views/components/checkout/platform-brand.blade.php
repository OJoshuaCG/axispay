{{--
    The platform in the checkout's "Powered by" line (ADR-0038, ADR-0053):
    logo, name, or both, as the superadmin chose; the name alone without a
    logo. The logo is served same-origin (img-src 'self'). The dark variant
    replaces the light one in the dark theme when it exists. The logo's alt
    text is the name; with the visible name too, the name is aria-hidden so
    it is not read twice.
--}}
@php
    $brand = app(\App\Modules\Branding\Services\PlatformBrand::class);
    $light = $brand->showsLogo() ? $brand->logoUrl(\App\Modules\Branding\Enums\LogoVariant::Light) : null;
    $dark = $brand->hasVariant(\App\Modules\Branding\Enums\LogoVariant::Dark) ? $brand->logoUrl(\App\Modules\Branding\Enums\LogoVariant::Dark) : null;
@endphp
<span class="inline-flex items-center gap-1 align-middle" data-brand-mode="{{ $brand->mode()->value }}">@if ($light !== null)<img src="{{ $light }}" alt="{{ $brand->name() }}" class="inline-block h-5 w-auto max-w-32 object-contain {{ $dark !== null ? 'dark:hidden' : '' }}" decoding="async" loading="lazy" />@if ($dark !== null)<img src="{{ $dark }}" alt="{{ $brand->name() }}" class="hidden h-5 w-auto max-w-32 object-contain dark:inline-block" decoding="async" loading="lazy" />@endif @if ($brand->showsName())<span aria-hidden="true">{{ $brand->name() }}</span>@endif @else<span>{{ $brand->name() }}</span>@endif</span>
