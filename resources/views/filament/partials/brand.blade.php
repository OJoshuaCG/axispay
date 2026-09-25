{{--
    Panel brand: a 28px mark tile plus the product name (PanelDefaults
    ->brandLogo(), ADR-0044). Rendered by Filament's logo component in the
    topbar, the mobile sidebar header and above the sign-in card. A stand-in
    until the ADR-0038 logo exists; the tile is decorative (aria-hidden), the
    visible name is the accessible name of the surrounding link.
--}}
<span class="flex min-w-0 items-center gap-2">
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
    <span class="truncate text-lg font-bold tracking-snug text-fg">{{ \App\Modules\Shared\Support\Brand::displayName() }}</span>
</span>
