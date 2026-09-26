{{--
    Live checklist of the password policy (ADR-0046), shown under a
    new-password field by <x-password-input mode="new"> and
    PasswordField::forNewPassword(). Items come from PasswordPolicy::requirements(),
    so the list can never drift from the server rule, which stays
    authoritative: this is guidance only and never blocks a submit.

    Usage (internal): <x-password.requirements for="password" />
    Props: for (the id of the password <input>)

    One markup, two bindings, so the Blade pages and the Filament panels
    behave the same:
        - Blade pages: resources/js/password-input.js reads the data-*
          hooks (data-for, data-min, data-met, data-requirement-state,
          data-password-status). The x-* attributes are inert there.
        - Filament: Alpine (bundled with Livewire) runs the x-* attributes;
          password-input.js is not loaded. `wire:ignore` keeps Livewire's
          morph away from this client-only markup, so a round trip (the
          profile password is live) never resets it.
    Statements in x-init end with `;`: Blade's @js eats the newline after it.
    Only the length is kept in memory, never the password itself.

    Accessibility: each live item has a visually hidden "met / not met yet"
    state; a polite live region announces an item only when its state flips
    (not on every keystroke). The data-leak item can only be checked by the
    server, so it says "checked when you submit".
--}}
@props(['for'])

@php
    $items = \App\Modules\Identity\Support\PasswordPolicy::requirements();
    $met = __('identity.password.requirements.met');
    $notMet = __('identity.password.requirements.not_met');
    $titleId = $for.'-requirements-title';

    // For the Alpine watcher: [{min, label}] of the items the browser can check.
    $liveItems = array_values(array_map(
        static fn (array $item): array => ['min' => $item['min'], 'label' => $item['label']],
        array_filter($items, static fn (array $item): bool => $item['min'] !== null),
    ));
@endphp

<div
    id="{{ $for }}-requirements"
    data-password-requirements
    data-for="{{ $for }}"
    data-label-met="{{ $met }}"
    data-label-not-met="{{ $notMet }}"
    x-data="{ length: 0 }"
    x-init="
        const input = document.getElementById(@js($for));
        if (input) {
            length = input.value.length;
            input.addEventListener('input', () => { length = input.value.length; });
        }
        const items = @js($liveItems);
        const last = items.map((item) => length >= item.min);
        $watch('length', (value) => {
            items.forEach((item, index) => {
                const now = value >= item.min;
                if (now !== last[index]) {
                    last[index] = now;
                    $refs.status.textContent = item.label + ': ' + (now ? @js($met) : @js($notMet));
                }
            });
        });
    "
    wire:ignore
    {{ $attributes->class('grid gap-stack-xs text-sm') }}
>
    <p id="{{ $titleId }}" class="font-medium text-fg-secondary">{{ __('identity.password.requirements.title') }}</p>

    <ul class="grid gap-1" aria-labelledby="{{ $titleId }}">
        @foreach ($items as $item)
            @if ($item['min'] !== null)
                <li
                    class="group flex items-start gap-1.5 text-fg-secondary data-[met=true]:text-success"
                    data-requirement="{{ $item['key'] }}"
                    data-min="{{ $item['min'] }}"
                    data-label="{{ $item['label'] }}"
                    data-met="false"
                    x-bind:data-met="length >= {{ $item['min'] }} ? 'true' : 'false'"
                >
                    <span class="mt-0.5 inline-flex group-data-[met=true]:hidden"><x-icon name="minus-circle" variant="mini" size="sm" /></span>
                    <span class="mt-0.5 hidden group-data-[met=true]:inline-flex"><x-icon name="check-circle" variant="mini" size="sm" /></span>
                    <span class="min-w-0 break-words">
                        {{ $item['label'] }}<span class="sr-only">: <span data-requirement-state x-text="length >= {{ $item['min'] }} ? @js($met) : @js($notMet)">{{ $notMet }}</span></span>
                    </span>
                </li>
            @else
                <li class="flex items-start gap-1.5 text-fg-secondary" data-requirement="{{ $item['key'] }}">
                    <span class="mt-0.5 inline-flex"><x-icon name="clock" variant="mini" size="sm" /></span>
                    <span class="min-w-0 break-words">{{ $item['label'] }} ({{ __('identity.password.requirements.on_submit') }})</span>
                </li>
            @endif
        @endforeach
    </ul>

    <p class="break-words text-fg-secondary">{{ __('identity.password.guidance') }}</p>

    <p class="sr-only" aria-live="polite" data-password-status x-ref="status"></p>
</div>
