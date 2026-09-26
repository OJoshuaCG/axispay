{{--
    Password field molecule (ADR-0046): the one component for every password
    entry on a Blade page. Its Filament twin is
    App\Support\Filament\Forms\PasswordField (same modes, same reveal button,
    same checklist partials).

    Usage:
        Sign-in, re-authentication, "current password":
        <x-password-input name="password" :label="__('...')" :error="$errors->first('password')" required />

        Setting or changing a password (live policy checklist + confirmation):
        <x-password-input
            name="password"
            mode="new"
            :confirm="true"
            :label="__('identity.invitation.password')"
            :confirm-label="__('identity.invitation.password_confirmation')"
            :error="$errors->first('password')"
            required
        />

    Props:
        label, name, id, hint, error, wrapperClass: as <x-input>
        mode: current (default) | new
            current: autocomplete="current-password", no checklist.
            new: autocomplete="new-password", passwordrules for password
                 managers, and the live checklist built from
                 App\Modules\Identity\Support\PasswordPolicy.
        confirm: add a confirmation field (mode="new" only), named
                 confirmName (default "<name>_confirmation", Laravel's
                 `confirmed` rule), with its own reveal button and a
                 "do not match" hint.
        confirmLabel, confirmId, confirmError

    Every other attribute (required, autofocus, ...) goes to the main
    <input>; `required` is copied to the confirmation.

    Reveal button: a real <button type="button"> after the input, inside the
    same frame. aria-controls names the input, aria-pressed="true" means the
    password is visible, and the accessible name stays "Show password" (a
    toggle's name must not change with its state); the tooltip switches to
    "Hide password". 44x44px at every width. resources/js/password-input.js
    swaps the input type in place, keeps focus and caret, and masks every
    field again on submit so password managers still see a password field.
    Without JavaScript the button does nothing and the field stays a normal
    password input.

    The value is never written to any attribute, storage or log: the scripts
    keep only its length (checklist) and a boolean (mismatch).
--}}
@props([
    'label',
    'name' => 'password',
    'id' => null,
    'mode' => 'current',
    'confirm' => false,
    'confirmName' => null,
    'confirmLabel' => null,
    'confirmId' => null,
    'hint' => null,
    'error' => null,
    'confirmError' => null,
    'wrapperClass' => null,
])

@php
    if (! in_array($mode, ['current', 'new'], true)) {
        \App\Support\ComponentMisuse::report("Unknown <x-password-input> mode \"{$mode}\".", ['component' => 'password-input', 'mode' => $mode]);
        $mode = 'current';
    }

    $isNew = $mode === 'new';

    if ($confirm && ! $isNew) {
        \App\Support\ComponentMisuse::report('<x-password-input> only confirms a new password (mode="new").', ['component' => 'password-input', 'mode' => $mode]);
    }

    $hasConfirmation = $confirm && $isNew;

    $id = $id ?? $name;
    $confirmName = $confirmName ?? $name.'_confirmation';
    $confirmId = $confirmId ?? $confirmName;
    $confirmLabel = $confirmLabel ?? __('identity.invitation.password_confirmation');

    $isRequired = $attributes->has('required') && $attributes->get('required') !== false;

    $fields = [[
        'id' => $id,
        'name' => $name,
        'label' => $label,
        'hint' => $hint,
        'error' => $error,
        'main' => true,
    ]];

    if ($hasConfirmation) {
        $fields[] = [
            'id' => $confirmId,
            'name' => $confirmName,
            'label' => $confirmLabel,
            'hint' => null,
            'error' => $confirmError,
            'main' => false,
        ];
    }

    $showLabel = __('identity.password.show');
    $hideLabel = __('identity.password.hide');
@endphp

<div @class(['flex min-w-0 flex-col gap-stack-md', $wrapperClass]) data-password-input data-mode="{{ $mode }}">
    @foreach ($fields as $field)
        @php
            $hintId = filled($field['hint']) ? $field['id'].'-hint' : null;
            $errorId = filled($field['error']) ? $field['id'].'-error' : null;
            $extraId = $field['main']
                ? ($isNew ? $field['id'].'-requirements' : null)
                : $field['id'].'-mismatch';

            $describedBy = implode(' ', array_filter([
                $field['main'] ? $attributes->get('aria-describedby') : null,
                $hintId,
                $errorId,
                $extraId,
            ]));

            $inputAttributes = $field['main']
                ? $attributes->except(['aria-describedby', 'aria-invalid', 'id', 'type', 'name', 'value', 'autocomplete', 'class'])
                : new \Illuminate\View\ComponentAttributeBag(array_filter(['required' => $isRequired]));
        @endphp

        <div class="flex min-w-0 flex-col gap-stack-xs">
            <label for="{{ $field['id'] }}" class="text-sm font-medium text-fg">
                {{ $field['label'] }}
                @if ($isRequired)
                    <span class="text-error" aria-hidden="true">*</span>
                @endif
            </label>

            {{-- The frame draws the border and focus ring so the button sits inside it. --}}
            <div @class([
                'flex w-full items-stretch rounded-md border bg-page',
                'transition-colors duration-fast ease-standard',
                'has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus-ring',
                'has-[input:disabled]:bg-surface-alt has-[input:disabled]:opacity-disabled',
                'border-line-strong hover:border-fg-secondary' => ! $errorId,
                'border-error' => (bool) $errorId,
            ])>
                <input
                    id="{{ $field['id'] }}"
                    type="password"
                    name="{{ $field['name'] }}"
                    autocomplete="{{ $isNew ? 'new-password' : 'current-password' }}"
                    autocapitalize="none"
                    spellcheck="false"
                    data-password-field
                    @if ($isNew && $field['main']) passwordrules="minlength: {{ \App\Modules\Identity\Support\PasswordPolicy::minLength() }};" @endif
                    @if (! $field['main']) data-password-confirm-for="{{ $id }}" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    @if ($errorId) aria-invalid="true" @endif
                    {{ $inputAttributes->class([
                        'min-h-touch w-full min-w-0 bg-transparent ps-3 text-base text-fg outline-none',
                        'placeholder:text-fg-muted',
                    ]) }}
                />

                <button
                    type="button"
                    class="group inline-flex min-h-touch min-w-touch shrink-0 items-center justify-center rounded-md text-fg-secondary transition-colors duration-fast ease-standard hover:text-fg"
                    aria-controls="{{ $field['id'] }}"
                    aria-pressed="false"
                    aria-label="{{ $showLabel }}"
                    title="{{ $showLabel }}"
                    data-password-toggle
                    data-label-show="{{ $showLabel }}"
                    data-label-hide="{{ $hideLabel }}"
                >
                    <x-password.toggle-icons />
                </button>
            </div>

            @if ($hintId)
                <p id="{{ $hintId }}" class="text-sm break-words text-fg-secondary">{{ $field['hint'] }}</p>
            @endif

            @if ($errorId)
                <p id="{{ $errorId }}" class="flex items-start gap-1.5 text-sm text-error">
                    <x-icon name="exclamation-circle" variant="mini" size="sm" class="mt-0.5" />
                    <span class="min-w-0 break-words">{{ $field['error'] }}</span>
                </p>
            @endif

            @if ($field['main'] && $isNew)
                <x-password.requirements :for="$field['id']" />
            @endif

            @if (! $field['main'])
                <x-password.mismatch :for="$field['id']" :of="$id" />
            @endif
        </div>
    @endforeach
</div>
