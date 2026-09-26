{{--
    Controls next to the user menu, shared by both panels. Rendered through
    the USER_MENU_BEFORE render hook, which also re-renders inside Livewire
    updates, hence the explicit return path.

    Topbar (every regular page): the test/live selector (tenant panel), the
    language switcher and the theme control (md and up; below md Filament's
    user-menu switcher is the theme control, see the panel theme).

    Simple pages of a signed-in person ($simple: 2FA set-up and challenge,
    e-mail verification): language and theme only, at every width, matching
    the guest bar of the sign-in page. No test/live selector: the account is
    still being set up and the mode means nothing yet (the tenant banner
    already explains the state).
--}}
@php
    $simple ??= false;
    $returnPath = '/'.ltrim(\Livewire\Livewire::originalPath(), '/');
@endphp

<div class="pl-panel-controls">
    @if (! $simple && filament()->getId() === 'app')
        @include('filament.app.mode-switch', ['returnPath' => $returnPath])
    @endif

    <x-language-switcher :redirect="$returnPath" />

    <div @class(['flex' => $simple, 'hidden md:flex' => ! $simple])>
        @include('filament.partials.theme-control')
    </div>
</div>
