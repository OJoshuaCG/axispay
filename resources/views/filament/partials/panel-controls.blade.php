{{--
    Topbar controls shared by both panels: language switcher, theme control
    (md and up; below md Filament's user-menu switcher is the theme control,
    see the panel theme) and, in the tenant panel, the test/live selector.
    Rendered through the USER_MENU_BEFORE render hook, which also re-renders
    inside Livewire updates, hence the explicit return path.
--}}
@php
    $returnPath = '/'.ltrim(\Livewire\Livewire::originalPath(), '/');
@endphp

<div class="pl-panel-controls">
    @if (filament()->getId() === 'app')
        @include('filament.app.mode-switch', ['returnPath' => $returnPath])
    @endif

    <x-language-switcher :redirect="$returnPath" />

    <div class="hidden md:flex">
        @include('filament.partials.theme-control')
    </div>
</div>
