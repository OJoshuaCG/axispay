{{--
    Badge atom for short status labels.

    Usage: <x-badge variant="success" icon="check">Paid</x-badge>
    Variants: neutral | success | warning | error | info  (unknown: see ComponentMisuse, falls back to neutral)
    Badges never wrap (whitespace-nowrap) but never exceed their container
    either: a label longer than its container truncates with an ellipsis.
    Color is never the only signal: keep the text meaningful.
--}}
@props([
    'variant' => 'neutral',
    'icon' => null,
])

@php
    $variants = [
        'neutral' => 'bg-surface-alt text-fg',
        'success' => 'bg-success-subtle text-success',
        'warning' => 'bg-warning-subtle text-warning',
        'error' => 'bg-error-subtle text-error',
        'info' => 'bg-info-subtle text-info',
    ];

    if (! array_key_exists($variant, $variants)) {
        \App\Support\ComponentMisuse::report("Unknown <x-badge> variant \"{$variant}\".", ['component' => 'badge', 'variant' => $variant]);
        $variant = 'neutral';
    }
@endphp

<span {{ $attributes->class([
    'inline-flex max-w-full items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
    $variants[$variant],
]) }}>
    @if ($icon)
        <x-icon :name="$icon" variant="micro" size="xs" />
    @endif
    <span class="min-w-0 truncate">{{ $slot }}</span>
</span>
