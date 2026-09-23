{{--
    Card atom: bordered surface container.

    Usage:
        <x-card>Body</x-card>
        <x-card as="section" padding="lg" elevated>
            <x-slot:header>Title</x-slot:header>
            Body
            <x-slot:footer>Actions</x-slot:footer>
        </x-card>

    Props: as (div | section | article | aside), padding (none | sm | md | lg), elevated
    Invalid `as`/`padding`: ComponentMisuse policy (falls back to div / md).
--}}
@props([
    'as' => 'div',
    'padding' => 'md',
    'elevated' => false,
])

@php
    $paddings = [
        'none' => '',
        'sm' => 'p-inset-sm',
        'md' => 'p-inset-md',
        'lg' => 'p-inset-lg',
    ];

    if (! in_array($as, ['div', 'section', 'article', 'aside'], true)) {
        \App\Support\ComponentMisuse::report("Unknown <x-card> tag \"{$as}\".", ['component' => 'card', 'as' => $as]);
        $as = 'div';
    }

    if (! array_key_exists($padding, $paddings)) {
        \App\Support\ComponentMisuse::report("Unknown <x-card> padding \"{$padding}\".", ['component' => 'card', 'padding' => $padding]);
        $padding = 'md';
    }

    $tag = $as;
    $pad = $paddings[$padding];
@endphp

<{{ $tag }} {{ $attributes->class([
    'min-w-0 rounded-lg border border-line bg-surface text-fg break-words',
    'shadow-md' => $elevated,
    'shadow-xs' => ! $elevated,
]) }}>
    @isset($header)
        <div {{ $header->attributes->class(['border-b border-line font-semibold', $pad]) }}>
            {{ $header }}
        </div>
    @endisset

    <div class="{{ $pad }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div {{ $footer->attributes->class(['border-t border-line', $pad]) }}>
            {{ $footer }}
        </div>
    @endisset
</{{ $tag }}>
