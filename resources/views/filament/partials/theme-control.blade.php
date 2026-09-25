{{--
    Theme control of the Filament panels (ADR-0044): light / dark / system as a
    segmented group, visible on the sign-in pages and in the topbar (md and up).

    It drives Filament's own theme machinery, so there is one source of truth:
    a click dispatches Filament's `theme-changed` window event, whose listener
    (Filament's panel JS) saves localStorage['theme'] and toggles `.dark` on
    <html>; the dark-mode bridge (theme-bridge.blade.php) then mirrors that into
    `data-theme`. The pressed state starts from the saved preference, or the
    panel's default mode (light, PanelDefaults), and follows every
    `theme-changed` event, including one from Filament's user-menu switcher.

    Buttons with aria-pressed rather than a radio group: the panels load
    Alpine, not resources/js/theme.js, and a pressed-button group needs no
    roving-tabindex script. The translated label is the accessible name and
    the tooltip. Icons use <x-filament::icon> because the panels run
    DisableBladeIconComponents.
--}}
@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;

    $options = [
        'light' => ['icon' => Heroicon::Sun, 'label' => __('ui.theme.light')],
        'dark' => ['icon' => Heroicon::Moon, 'label' => __('ui.theme.dark')],
        'system' => ['icon' => Heroicon::ComputerDesktop, 'label' => __('ui.theme.system')],
    ];
@endphp

<div
    role="group"
    aria-label="{{ __('ui.theme.label') }}"
    data-panel-theme-control
    class="seg-group"
    x-data="{
        theme: (() => {
            try {
                return window.localStorage.getItem('theme')
            } catch (error) {
                return null
            }
        })() ?? @js(filament()->getDefaultThemeMode()->value),
    }"
    x-on:theme-changed.window="theme = $event.detail"
>
    @foreach ($options as $value => $option)
        <button
            type="button"
            class="seg-option"
            title="{{ $option['label'] }}"
            aria-pressed="false"
            x-bind:aria-pressed="theme === @js($value) ? 'true' : 'false'"
            x-on:click="$dispatch('theme-changed', @js($value))"
        >
            <x-filament::icon :icon="$option['icon']" :size="IconSize::Small" />
            <span class="sr-only">{{ $option['label'] }}</span>
        </button>
    @endforeach
</div>
