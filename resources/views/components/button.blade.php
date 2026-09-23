{{--
    Button atom. Renders <a> when `href` is given, otherwise <button>.

    Props:
        variant:      primary | secondary | accent | ghost | danger   ("outline" aliases secondary)
        size:         sm | md | lg
        href, type, disabled
        loading:      shows a spinner and announces `loadingLabel`
        loadingLabel: screen-reader text while loading (default __('ui.button.loading')).
                      Also written to data-loading-label so resources/js/forms.js
                      announces the same translated text for client-side loading.
        icon:         leading icon; with an empty slot it becomes an icon-only button
        iconTrailing: trailing icon
        label:        accessible name, REQUIRED for icon-only buttons (pass a translated string)

    Variants:
        primary  the main (transactional) action
        accent   non-transactional calls to action only (marketing, onboarding).
                 Never for pay/confirm. Brand green-500 is decorative only
                 (2.65:1 on white); the fill uses green-700.
        ghost    low-emphasis action; hover = surface-alt, pressed = surface-pressed
        danger   destructive actions (refund, cancel, delete); label uses on-error

    Invalid input (unknown variant/size, icon-only without label) follows the
    ComponentMisuse policy: throw in local/testing, otherwise log and fall back
    to secondary / md.

    Touch targets: md and lg are at least 44px tall. sm LOOKS compact (36px) but
    a transparent ::before extends its hit area by 4px on every side, so the
    tappable area is still 44px tall (44x44 when icon-only). Keep at least 8px
    (gap-2) between adjacent sm buttons so hit areas do not overlap.

    Long labels wrap (centered) instead of overflowing narrow screens; the
    minimum height is kept.

    Loading keeps the button FOCUSABLE (no disabled attribute, so focus is not
    lost mid-submit): it sets aria-disabled + aria-busy + data-loading, and
    resources/js/forms.js blocks activation. Server-side idempotency is still
    required: client-side guards are a UX aid, not a payment safeguard.

    Class overrides: consumers may add layout classes (w-full, mt-*), but
    padding, height and font size must come from the `size` prop. Classes are
    not merged by conflict (no tailwind-merge), so an extra px-* or text-* would
    compete with the size classes unpredictably.
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
    'loadingLabel' => null,
    'icon' => null,
    'iconTrailing' => null,
    'label' => null,
])

@php
    $variant = $variant === 'outline' ? 'secondary' : $variant;
    $isLink = filled($href);
    $iconOnly = filled($icon) && $slot->isEmpty();
    $loadingLabel = $loadingLabel ?? __('ui.button.loading');

    if ($iconOnly && blank($label)) {
        \App\Support\ComponentMisuse::report("Icon-only <x-button icon=\"{$icon}\"> requires a `label` for its accessible name.", ['component' => 'button', 'icon' => $icon]);
    }

    $variants = [
        'primary' => 'bg-primary text-on-primary hover:bg-primary-hover active:bg-primary-active',
        'secondary' => 'border border-line-strong bg-page text-fg hover:bg-surface active:bg-surface-alt',
        'accent' => 'bg-accent-fill text-on-accent hover:bg-accent-hover active:bg-accent-active',
        'ghost' => 'bg-transparent text-fg hover:bg-surface-alt active:bg-surface-pressed',
        'danger' => 'bg-error-fill text-on-error hover:bg-error-fill-hover active:bg-error-fill-active',
    ];

    $sizes = $iconOnly
        ? ['sm' => 'size-9 shrink-0 p-0 before:absolute before:-inset-1', 'md' => 'size-touch shrink-0 p-0', 'lg' => 'size-12 shrink-0 p-0']
        : [
            'sm' => 'min-h-9 gap-1.5 px-3 py-1.5 text-sm before:absolute before:-inset-1',
            'md' => 'min-h-touch gap-2 px-4 py-2 text-base',
            'lg' => 'min-h-12 gap-2 px-6 py-2.5 text-lg',
        ];

    if (! array_key_exists($variant, $variants)) {
        \App\Support\ComponentMisuse::report("Unknown <x-button> variant \"{$variant}\".", ['component' => 'button', 'variant' => $variant]);
        $variant = 'secondary';
    }

    if (! array_key_exists($size, $sizes)) {
        \App\Support\ComponentMisuse::report("Unknown <x-button> size \"{$size}\".", ['component' => 'button', 'size' => $size]);
        $size = 'md';
    }

    $iconSize = ['sm' => 'sm', 'md' => 'md', 'lg' => 'md'][$size];

    $classes = [
        'relative inline-flex max-w-full select-none items-center justify-center rounded-md text-center font-medium no-underline',
        'transition-colors duration-fast ease-standard',
        'disabled:pointer-events-none disabled:opacity-disabled',
        'aria-disabled:pointer-events-none aria-disabled:opacity-disabled',
        $variants[$variant],
        $sizes[$size],
    ];

    $state = array_filter([
        'aria-label' => $label,
        'aria-disabled' => ($loading || ($isLink && $disabled)) ? 'true' : null,
        'aria-busy' => $loading ? 'true' : null,
        'data-loading' => $loading ? '' : null,
        'data-loading-label' => $isLink ? null : $loadingLabel,
    ], fn ($value) => ! is_null($value));
@endphp

@if ($isLink)
    <a
        @unless ($disabled || $loading) href="{{ $href }}" @endunless
        @if ($disabled || $loading) role="link" @endif
        {{ $attributes->merge($state)->class($classes) }}
    >
@else
    <button
        type="{{ $type }}"
        @disabled($disabled)
        {{ $attributes->merge($state)->class($classes) }}
    >
@endif
    @if ($loading)
        <svg data-button-spinner class="size-icon-sm shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <span class="sr-only" role="status">{{ $loadingLabel }}</span>
    @elseif ($icon)
        <x-icon :name="$icon" :size="$iconSize" />
    @endif

    @unless ($iconOnly)
        <span class="min-w-0 break-words">{{ $slot }}</span>
    @endunless

    @if ($iconTrailing && ! $loading)
        <x-icon :name="$iconTrailing" :size="$iconSize" />
    @endif
@if ($isLink)
    </a>
@else
    </button>
@endif
