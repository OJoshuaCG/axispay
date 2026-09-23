{{--
    Icon atom: server-rendered Heroicons SVG that inherits currentColor.

    Usage:
        <x-icon name="check-circle" />                        outline 24px set (default)
        <x-icon name="check-circle" variant="mini" size="sm" /> 20px solid set
        <x-icon name="heroicon-s-bolt" />                     full blade-icons name
        <x-icon name="lock-closed" label="Secure payment" />  meaningful icon

    Decorative by default (aria-hidden). Passing a label exposes it as role="img";
    the label is user-facing, so pass a translated string (__('...')).
    Unknown icon/variant/size: ComponentMisuse policy (elsewhere: empty,
    same-sized placeholder).
--}}
@props([
    'name',
    'variant' => 'outline', // outline | solid | mini | micro
    'size' => 'md', // xs | sm | md | lg | xl
    'label' => null,
])

@php
    $prefixes = ['outline' => 'o', 'solid' => 's', 'mini' => 'm', 'micro' => 'c'];

    $sizes = [
        'xs' => 'size-icon-xs',
        'sm' => 'size-icon-sm',
        'md' => 'size-icon-md',
        'lg' => 'size-icon-lg',
        'xl' => 'size-icon-xl',
    ];

    if (! array_key_exists($variant, $prefixes)) {
        \App\Support\ComponentMisuse::report("Unknown <x-icon> variant \"{$variant}\".", ['component' => 'icon', 'variant' => $variant]);
        $variant = 'outline';
    }

    if (! array_key_exists($size, $sizes)) {
        \App\Support\ComponentMisuse::report("Unknown <x-icon> size \"{$size}\".", ['component' => 'icon', 'size' => $size]);
        $size = 'md';
    }

    $iconName = str_starts_with($name, 'heroicon-')
        ? $name
        : 'heroicon-'.$prefixes[$variant].'-'.$name;

    $classes = ['inline-block max-w-full shrink-0', $sizes[$size]];

    try {
        $contents = svg($iconName)->contents();
    } catch (\BladeUI\Icons\Exceptions\SvgNotFound $exception) {
        // Throws in local/testing; elsewhere degrades to an empty, same-sized
        // placeholder instead of breaking the page.
        \App\Support\ComponentMisuse::report("Unknown icon \"{$iconName}\".", ['component' => 'icon', 'icon' => $iconName], $exception);
        $contents = null;
    }

    if ($contents !== null) {
        // Drop the attributes baked into the Heroicons files so accessibility
        // attributes are controlled here, once, and escaped by Blade.
        $contents = preg_replace(['/\saria-hidden="true"/', '/\sdata-slot="icon"/'], '', $contents);

        $a11y = filled($label)
            ? ['role' => 'img', 'aria-label' => $label]
            : ['aria-hidden' => 'true'];

        $svgAttributes = $attributes->class($classes)->merge($a11y + ['focusable' => 'false']);

        $contents = preg_replace('/<svg\b/', '<svg '.$svgAttributes->toHtml(), $contents, 1);
    }
@endphp

@if ($contents !== null)
    {!! $contents !!}
@else
    <span {{ $attributes->class($classes) }} aria-hidden="true"></span>
@endif
