{{--
    Display settings for page headers: language switcher + theme toggle.

    Usage: <x-site-controls />  or  <x-site-controls theme-labels />

    Wraps instead of overflowing: on narrow screens the two groups stack onto a
    new line of the header (the header itself must use flex-wrap). Theme labels
    are shown only from `md` up when `theme-labels` is set; below that the
    toggle is icon-only (labels stay available to screen readers and as
    tooltips).
--}}
@props([
    'themeLabels' => false,
])

<div
    role="group"
    aria-label="{{ __('ui.layout.site_controls') }}"
    {{ $attributes->class('flex max-w-full flex-wrap items-center gap-2') }}
>
    <x-language-switcher />
    <x-theme-toggle :show-labels="$themeLabels" />
</div>
