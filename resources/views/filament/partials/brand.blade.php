{{--
    Panel brand (PanelDefaults ->brandLogo() / ->darkModeBrandLogo(),
    ADR-0053, ADR-0054): the platform logo, the name, or both, as the
    superadmin chose. Filament renders it in the topbar (below lg), the
    sidebar header (the mobile drawer, and the full-height sidebar from lg)
    and above the sign-in, 2FA, invitation and password-reset cards; Filament
    swaps the light and dark renders.

    One markup for every place: the size and the stacking come from
    resources/css/filament/theme.css (.pl-brand, by context) and the
    --brand-logo-* tokens in resources/css/theme.css.

    - Logo shown: its alt text is the name, so "logo only" keeps an
      accessible name. With the visible name too, the name is aria-hidden so
      it is not read twice.
    - No logo (or "name only"): the stand-in mark tile (decorative) and the
      name, as before the logo existed.

    Props: variant (LogoVariant, default light).
--}}
@php
    $brand = app(\App\Modules\Branding\Services\PlatformBrand::class);
    $variant ??= \App\Modules\Branding\Enums\LogoVariant::Light;
    $logoUrl = $brand->showsLogo() ? $brand->logoUrl($variant) : null;
@endphp
<span class="pl-brand" data-brand-mode="{{ $brand->mode()->value }}" data-brand-kind="{{ $logoUrl !== null ? 'logo' : 'mark' }}">
    @if ($logoUrl !== null)
        <img src="{{ $logoUrl }}" alt="{{ $brand->name() }}" class="pl-brand-logo" decoding="async" />
        @if ($brand->showsName())
            <span class="pl-brand-name" aria-hidden="true">{{ $brand->name() }}</span>
        @endif
    @else
        <svg viewBox="0 0 28 28" class="pl-brand-mark" aria-hidden="true" focusable="false">
            <rect width="28" height="28" rx="7" class="fill-brand-mark" />
            <path
                d="M8.5 20 14 8l5.5 12M10.6 15.5h6.8"
                fill="none"
                stroke-width="2.25"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="stroke-brand-mark-fg"
            />
        </svg>
        <span class="pl-brand-name">{{ $brand->name() }}</span>
    @endif
</span>
