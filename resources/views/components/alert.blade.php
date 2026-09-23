{{--
    Alert molecule for inline feedback messages.

    Usage:
        <x-alert variant="success" title="Payment received">The receipt was sent.</x-alert>
        <x-alert variant="error" title="Payment declined" focus>Try another card.</x-alert>

    Variants: success | warning | error | info  (unknown: see ComponentMisuse, falls back to info)
    Each alert starts with a visually hidden, translated severity prefix
    (ui.alert.*, e.g. "Error:") so the meaning never depends on color or icon.

    Live regions: error and warning use role="alert" (assertive); success and
    info use role="status" (polite). Screen readers do not reliably announce live
    regions that are already in the DOM when the page loads, so an alert that
    is rendered with the initial page (e.g. after a failed form submit) should
    receive focus: pass `focus`. It adds tabindex="-1" and data-autofocus, and
    resources/js/focus.js moves focus to it on load. Override `role` with
    role="note" for static, non-urgent notes.
--}}
@props([
    'variant' => 'info',
    'title' => null,
    'focus' => false,
])

@php
    $variants = [
        'success' => ['classes' => 'border-success bg-success-subtle', 'icon' => 'check-circle', 'iconColor' => 'text-success', 'role' => 'status'],
        'warning' => ['classes' => 'border-warning bg-warning-subtle', 'icon' => 'exclamation-triangle', 'iconColor' => 'text-warning', 'role' => 'alert'],
        'error' => ['classes' => 'border-error bg-error-subtle', 'icon' => 'x-circle', 'iconColor' => 'text-error', 'role' => 'alert'],
        'info' => ['classes' => 'border-info bg-info-subtle', 'icon' => 'information-circle', 'iconColor' => 'text-info', 'role' => 'status'],
    ];

    if (! array_key_exists($variant, $variants)) {
        \App\Support\ComponentMisuse::report("Unknown <x-alert> variant \"{$variant}\".", ['component' => 'alert', 'variant' => $variant]);
        $variant = 'info';
    }

    $config = $variants[$variant];
    // Trailing space keeps the hidden prefix separate from the title when read.
    $prefix = __('ui.alert.'.$variant).' ';

    $defaults = ['role' => $config['role']];

    if ($focus) {
        $defaults += ['tabindex' => '-1', 'data-autofocus' => ''];
    }
@endphp

<div {{ $attributes->merge($defaults)->class([
    'flex gap-3 rounded-md border-s-4 p-inset-md text-sm text-fg break-words',
    $config['classes'],
]) }}>
    <x-icon :name="$config['icon']" variant="mini" :class="$config['iconColor']" />

    <div class="flex min-w-0 flex-col gap-stack-xs">
        @if ($title)
            <p class="font-semibold"><span class="sr-only">{{ $prefix }}</span>{{ $title }}</p>
            <div>{{ $slot }}</div>
        @else
            <div><span class="sr-only">{{ $prefix }}</span>{{ $slot }}</div>
        @endif
    </div>
</div>
