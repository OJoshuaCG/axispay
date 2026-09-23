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
        - Each option is named by its NATIVE name ("English", "Español") and
          carries lang="<code>" so screen readers pronounce it correctly.
        - The active language is marked aria-pressed="true".
        - Below the `sm` breakpoint the visible text is the code ("EN", "ES")
          to fit 320px headers; the native name stays as the accessible name
          (the visible code is part of it, satisfying label-in-name).
        - Every option is at least 44x44px.
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
        class="inline-flex items-center gap-0.5 rounded-full border border-line bg-surface p-0.5"
    >
        @foreach ($locales as $code => $nativeName)
            <button
                type="submit"
                name="locale"
                value="{{ $code }}"
                lang="{{ $code }}"
                aria-pressed="{{ $code === $current ? 'true' : 'false' }}"
                class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-full px-3 text-sm font-medium text-fg-secondary transition-colors duration-fast ease-standard hover:text-fg aria-pressed:bg-page aria-pressed:text-fg aria-pressed:shadow-sm aria-pressed:ring-1 aria-pressed:ring-line-strong"
            >
                <span class="uppercase sm:hidden" aria-hidden="true">{{ $code }}</span>
                <span class="sr-only sm:not-sr-only">{{ $nativeName }}</span>
            </button>
        @endforeach
    </div>
</form>
