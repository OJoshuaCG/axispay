{{--
    Branding page (ADR-0053): preview of one logo variant on the background
    it is shown on (light panels / dark panels), whatever theme the viewer
    uses now. Fixed neutral primitives on purpose: the preview must look the
    same in both themes.

    Props: variant (LogoVariant), url (?string), fallback (bool: the dark
    preview is showing the light logo because there is no dark variant).
--}}
@php
    $isDark = $variant === \App\Modules\Branding\Enums\LogoVariant::Dark;
@endphp
<figure class="flex flex-col gap-stack-sm">
    <figcaption class="text-sm font-medium text-fg">{{ $variant->label() }}</figcaption>
    <div @class([
        'flex min-h-24 items-center justify-center rounded-lg border border-line p-inset-md',
        'bg-neutral-0 text-neutral-900' => ! $isDark,
        'bg-neutral-900 text-neutral-50' => $isDark,
    ])>
        @if ($url !== null)
            <img src="{{ $url }}" alt="{{ __('branding.preview.alt', ['variant' => $variant->label(), 'name' => \App\Modules\Shared\Support\Brand::displayName()]) }}" class="block h-12 w-auto max-w-full object-contain" />
        @else
            <span class="text-sm">{{ __('branding.preview.empty') }}</span>
        @endif
    </div>
    @if ($fallback)
        <p class="text-sm text-fg-secondary">{{ __('branding.preview.dark_fallback') }}</p>
    @endif
</figure>
