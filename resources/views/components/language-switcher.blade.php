{{--
    Language switcher molecule: one submit button per supported locale.

    Usage: <x-language-switcher />
           <x-language-switcher :redirect="$path" />   (explicit return path, e.g. from a Livewire re-render)

    Works without JavaScript: a plain POST form (CSRF-protected) to
    route('locale.update'), which stores the choice in the `locale` cookie and
    redirects back to the current page (any ?lang= is dropped). Locales and
    their native names come from config('app.supported_locales').

    Accessibility:
        - role="group" named by ui.language.label ("Language" / "Idioma").
        - The visible text is the code ("EN", "ES") at every width, so the
          control stays compact in toolbars (ADR-0044). The accessible name is
          "EN English": the visible code first (label-in-name, WCAG 2.5.3),
          then the NATIVE name in sr-only text; the native name is also the
          tooltip. Each option carries lang="<code>" so screen readers
          pronounce it correctly.
        - The active language is marked aria-pressed="true".
        - Styled by the seg-group / seg-option utilities (components.css):
          44x44px options, 36x30 on `desktop:` (large screen, fine pointer).
--}}
@props([
    'redirect' => null,
])

@php
    $locales = \App\Support\Locales::supported();
    $current = app()->getLocale();
@endphp

<form
    method="POST"
    action="{{ route('locale.update') }}"
    {{ $attributes->class('inline-flex max-w-full shrink-0') }}
>
    @csrf
    <input type="hidden" name="redirect" value="{{ $redirect ?? request()->getRequestUri() }}">

    <div
        role="group"
        aria-label="{{ __('ui.language.label') }}"
        class="seg-group"
    >
        @foreach ($locales as $code => $nativeName)
            <button
                type="submit"
                name="locale"
                value="{{ $code }}"
                lang="{{ $code }}"
                title="{{ $nativeName }}"
                aria-pressed="{{ $code === $current ? 'true' : 'false' }}"
                class="seg-option"
            >
                <span>{{ strtoupper($code) }}</span><span class="sr-only"> {{ $nativeName }}</span>
            </button>
        @endforeach
    </div>
</form>
