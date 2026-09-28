{{--
    Panel brand (PanelDefaults ->brandLogo() / ->darkModeBrandLogo(),
    ADR-0053): the platform logo, the name, or both, as the superadmin chose.
    Rendered by Filament's logo component in the topbar, the mobile sidebar
    header and above the sign-in, 2FA and invitation cards; Filament swaps the
    light and dark renders.

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
<span class="flex h-full min-w-0 items-center gap-2" data-brand-mode="{{ $brand->mode()->value }}">
    @if ($logoUrl !== null)
        <img src="{{ $logoUrl }}" alt="{{ $brand->name() }}" class="block h-full w-auto max-w-48 shrink-0 object-contain" decoding="async" />
        @if ($brand->showsName())
            <span class="truncate text-lg font-bold tracking-snug text-fg" aria-hidden="true">{{ $brand->name() }}</span>
        @endif
    @else
        <svg viewBox="0 0 28 28" class="size-7 shrink-0" aria-hidden="true" focusable="false">
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
        <span class="truncate text-lg font-bold tracking-snug text-fg">{{ $brand->name() }}</span>
    @endif
</span>
