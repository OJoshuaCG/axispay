{{--
    Eye / eye-slash pair of the password reveal button (ADR-0046). Shared by
    <x-password-input> and App\Support\Filament\Forms\PasswordField, so both
    stacks show the same icon. The button carries `group` and aria-pressed;
    CSS alone picks the icon (pressed = password visible = eye-slash), so no
    script has to swap markup and a Livewire re-render cannot desync it.
--}}
<x-icon name="eye" size="sm" class="group-aria-pressed:hidden" />
<span class="hidden group-aria-pressed:inline-flex">
    <x-icon name="eye-slash" size="sm" />
</span>
