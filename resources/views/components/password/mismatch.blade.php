{{--
    "The passwords do not match" hint under a confirmation field (ADR-0046),
    used by <x-password-input :confirm="true"> and
    PasswordField::confirms(). Same two bindings as
    <x-password.requirements>: data-* hooks for resources/js/password-input.js
    on Blade pages, x-* for Alpine in Filament (`wire:ignore`: Livewire's
    morph never resets it). Only a boolean is kept, never the values.

    Usage (internal): <x-password.mismatch for="password_confirmation" of="password" />
    Props: for (id of the confirmation input), of (id of the password input)

    The message appears once the confirmation is at least as long as the
    password and differs, so it does not nag while the person is still typing;
    it is a polite live region, always rendered (a region added or unhidden
    together with its text is often not announced). The server's
    `confirmed` / `same` rule stays authoritative.
--}}
@props(['for', 'of'])

@php
    $message = __('identity.password.mismatch');
@endphp

<p
    id="{{ $for }}-mismatch"
    data-password-mismatch
    data-for="{{ $for }}"
    data-of="{{ $of }}"
    data-message="{{ $message }}"
    aria-live="polite"
    x-data="{ mismatch: false }"
    x-init="
        const confirmation = document.getElementById(@js($for));
        const password = document.getElementById(@js($of));
        if (confirmation && password) {
            const sync = () => {
                mismatch = confirmation.value !== ''
                    && confirmation.value.length >= password.value.length
                    && confirmation.value !== password.value;
            };
            confirmation.addEventListener('input', sync);
            password.addEventListener('input', sync);
        }
    "
    wire:ignore
    x-text="mismatch ? @js($message) : ''"
    {{ $attributes->class('text-sm break-words text-error') }}
></p>
