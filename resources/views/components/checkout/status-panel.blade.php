{{--
    Terminal or waiting state of the checkout (DESIGN.md): an icon in a
    *-subtle circle, the page's <h1> (focusable, focused on arrival when
    `focus`), a body and optional actions. No illustrations.

    Props:
        variant  success | info | warning | error | neutral
        icon     Heroicons name
        heading  the h1 text
        focus    bool: move focus to the heading on load (data-autofocus)
        level    1 (the page's heading, default) or 2 (previews in /design-system)

    Slots: default (body), actions.
--}}
@props(['variant' => 'info', 'icon' => 'information-circle', 'heading', 'focus' => false, 'level' => 1])

@php
    $variants = [
        'success' => ['circle' => 'bg-success-subtle text-success'],
        'info' => ['circle' => 'bg-info-subtle text-info'],
        'warning' => ['circle' => 'bg-warning-subtle text-warning'],
        'error' => ['circle' => 'bg-error-subtle text-error'],
        'neutral' => ['circle' => 'bg-surface-alt text-fg-secondary'],
    ];

    if (! array_key_exists($variant, $variants)) {
        \App\Support\ComponentMisuse::report("Unknown <x-checkout.status-panel> variant \"{$variant}\".", ['component' => 'checkout.status-panel', 'variant' => $variant]);
        $variant = 'neutral';
    }
@endphp

<section {{ $attributes->class('flex flex-col items-start gap-stack-md') }} data-status-panel>
    <span class="flex size-12 shrink-0 items-center justify-center rounded-full {{ $variants[$variant]['circle'] }}" aria-hidden="true">
        <x-icon :name="$icon" size="lg" />
    </span>

    @if ((int) $level === 2)
        <h2 tabindex="-1" class="text-2xl font-semibold break-words text-fg focus-visible:outline-none">{{ $heading }}</h2>
    @else
        <h1 tabindex="-1" @if ($focus) data-autofocus @endif class="text-2xl font-semibold break-words text-fg focus-visible:outline-none">{{ $heading }}</h1>
    @endif

    <div class="flex w-full flex-col gap-stack-sm break-words text-fg">
        {{ $slot }}
    </div>

    @if (isset($actions) && $actions->isNotEmpty())
        <div class="flex w-full flex-wrap gap-stack-sm pt-stack-sm">
            {{ $actions }}
        </div>
    @endif
</section>
