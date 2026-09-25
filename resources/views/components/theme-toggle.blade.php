{{--
    Theme toggle molecule: light / dark / system as a single-choice radio group.

    Keyboard (resources/js/theme.js): Tab focuses the checked option; arrow keys
    move and select (roving tabindex), Home/End jump to the ends.

    Nothing is marked checked server-side (the saved preference lives in
    localStorage and is unknown to the server); theme.js marks the correct
    option as soon as the module runs. Until then the first option is the tab
    stop so the group stays reachable.

    show-labels: text labels appear from the `md` breakpoint up; below it the
    toggle stays icon-only (44x44 per option) so it fits 320px headers. The
    label is always the accessible name and the title tooltip.

    Styled by seg-group / seg-option (components.css), the same family as the
    language switcher and the panel theme control (ADR-0044).

    All labels are translated (ui.theme.*). theme.js announces nothing itself:
    the checked state is exposed through aria-checked on the translated radios.
--}}
@props([
    'showLabels' => false,
])

@php
    $options = [
        'light' => ['icon' => 'sun', 'label' => __('ui.theme.light')],
        'dark' => ['icon' => 'moon', 'label' => __('ui.theme.dark')],
        'system' => ['icon' => 'computer-desktop', 'label' => __('ui.theme.system')],
    ];
@endphp

<div
    role="radiogroup"
    aria-label="{{ __('ui.theme.label') }}"
    data-theme-toggle
    {{ $attributes->class('seg-group') }}
>
    @foreach ($options as $value => $option)
        <button
            type="button"
            role="radio"
            aria-checked="false"
            tabindex="{{ $loop->first ? '0' : '-1' }}"
            data-theme-option="{{ $value }}"
            title="{{ $option['label'] }}"
            @class([
                'seg-option',
                'md:px-3' => $showLabels,
            ])
        >
            <x-icon :name="$option['icon']" variant="mini" size="sm" />
            <span @class(['sr-only', 'md:not-sr-only' => $showLabels])>{{ $option['label'] }}</span>
        </button>
    @endforeach
</div>
