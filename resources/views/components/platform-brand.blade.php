{{--
    The platform brand at sign-in size, for Blade pages that play the role of
    the panels' simple pages (invitation acceptance; ADR-0053, ADR-0054). Same
    rules as the panel partial (filament/partials/brand.blade.php): logo,
    name or both as the superadmin chose, the name alone without a logo; the
    logo's alt text is the name; with the visible name too, the name is
    aria-hidden. The dark variant replaces the light one in the dark theme
    when it exists. Sizes: the --brand-logo-simple-* tokens.
--}}
@php
    $brand = app(\App\Modules\Branding\Services\PlatformBrand::class);
    $light = $brand->showsLogo() ? $brand->logoUrl(\App\Modules\Branding\Enums\LogoVariant::Light) : null;
    $dark = $light !== null && $brand->hasVariant(\App\Modules\Branding\Enums\LogoVariant::Dark) ? $brand->logoUrl(\App\Modules\Branding\Enums\LogoVariant::Dark) : null;
@endphp
<div {{ $attributes->class('flex flex-col items-center gap-stack-sm text-center') }} data-brand-mode="{{ $brand->mode()->value }}" data-brand-kind="{{ $light !== null ? 'logo' : 'mark' }}">
    @if ($light !== null)
        <img src="{{ $light }}" alt="{{ $brand->name() }}" class="brand-logo-simple {{ $dark !== null ? 'block dark:hidden' : 'block' }}" decoding="async" />
        @if ($dark !== null)
            <img src="{{ $dark }}" alt="{{ $brand->name() }}" class="brand-logo-simple hidden dark:block" decoding="async" />
        @endif
        @if ($brand->showsName())
            <span class="break-words text-2xl font-bold tracking-snug text-fg" aria-hidden="true">{{ $brand->name() }}</span>
        @endif
    @else
        <span class="flex max-w-full items-center gap-stack-sm">
            <svg viewBox="0 0 28 28" class="brand-mark-simple" aria-hidden="true" focusable="false">
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
            <span class="min-w-0 break-words text-2xl font-bold tracking-snug text-fg">{{ $brand->name() }}</span>
        </span>
    @endif
</div>
