{{--
    Text input atom with label, hint, error and affix wiring.

    Usage:
        <x-input name="email" type="email" label="Email" hint="We never share it." autocomplete="email" required />
        <x-input name="email" label="Email" :error="$errors->first('email')" />
        <x-input name="amount" label="Amount" inputmode="decimal" align="end" class="amount">
            <x-slot:prefix>$</x-slot:prefix>
            <x-slot:suffix>USD</x-slot:suffix>
        </x-input>

    Props: label, name, id, type, value, hint, error, hideLabel, align (start | end),
           wrapperClass (classes for the outer wrapper: grid placement, width, margins)
    Slots: prefix, suffix (short, non-interactive text such as a currency symbol or code)

    Class routing: `class` goes to the <input> (e.g. class="amount");
    `wrapper-class` goes to the outer wrapper (e.g. wrapper-class="md:col-span-2").

    Amount inputs: class="amount" sets the numeric face (Geist Mono,
    --font-numeric) and tabular figures, like <x-amount> (ADR-0042). There is
    no separate `numeric` prop on purpose: one way to mark money.

    Label, hint, error and affix text are user-facing: pass translated strings.
    The control is always text-base (16px), so iOS Safari does not zoom on focus.

    Every other attribute (placeholder, inputmode, autocomplete, required,
    readonly, ...) is passed to the <input>. A caller's aria-describedby is kept
    and merged with the hint/error IDs. The component never reads request or
    session data itself.
--}}
@props([
    'label',
    'name' => null,
    'id' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'error' => null,
    'hideLabel' => false,
    'align' => 'start',
    'wrapperClass' => null,
])

@php
    $id = $id ?? $name ?? 'input-'.\Illuminate\Support\Str::random(8);
    $hintId = filled($hint) ? $id.'-hint' : null;
    $errorId = filled($error) ? $id.'-error' : null;
    $prefixId = isset($prefix) && $prefix->isNotEmpty() ? $id.'-prefix' : null;
    $suffixId = isset($suffix) && $suffix->isNotEmpty() ? $id.'-suffix' : null;

    // Affixes (e.g. "USD") are announced after the label; then caller, hint, error.
    $describedBy = implode(' ', array_filter([
        $prefixId,
        $suffixId,
        $attributes->get('aria-describedby'),
        $hintId,
        $errorId,
    ]));

    $isRequired = $attributes->has('required') && $attributes->get('required') !== false;
    $hasAffix = $prefixId || $suffixId;

    $control = [
        'min-h-touch w-full min-w-0 bg-transparent px-3 text-base text-fg outline-none',
        'placeholder:text-fg-muted',
        'text-end' => $align === 'end',
    ];

    $frame = [
        'flex w-full items-stretch rounded-md border bg-page',
        'transition-colors duration-fast ease-standard',
        'has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus-ring',
        'has-[input:disabled]:bg-surface-alt has-[input:disabled]:opacity-disabled',
        'has-[input:read-only:enabled]:border-line has-[input:read-only:enabled]:bg-surface',
        'border-line-strong hover:border-fg-secondary' => ! $errorId,
        'border-error' => (bool) $errorId,
    ];
@endphp

<div @class(['flex min-w-0 flex-col gap-stack-xs', $wrapperClass])>
    <label for="{{ $id }}" @class(['text-sm font-medium text-fg', 'sr-only' => $hideLabel])>
        {{ $label }}
        @if ($isRequired)
            <span class="text-error" aria-hidden="true">*</span>
        @endif
    </label>

    @if ($hasAffix)
        {{-- The frame draws the border and focus ring so affixes sit inside it. --}}
        <div @class($frame)>
            @if ($prefixId)
                <span id="{{ $prefixId }}" class="flex shrink-0 items-center ps-3 text-fg-secondary select-none">{{ $prefix }}</span>
            @endif

            <input
                id="{{ $id }}"
                type="{{ $type }}"
                @if ($name) name="{{ $name }}" @endif
                @if (! is_null($value)) value="{{ $value }}" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                @if ($errorId) aria-invalid="true" @endif
                {{ $attributes->except(['aria-describedby', 'aria-invalid', 'id', 'type', 'name', 'value'])->class($control) }}
            />

            @if ($suffixId)
                <span id="{{ $suffixId }}" class="flex shrink-0 items-center pe-3 text-fg-secondary select-none">{{ $suffix }}</span>
            @endif
        </div>
    @else
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" @endif
            @if (! is_null($value)) value="{{ $value }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($errorId) aria-invalid="true" @endif
            {{ $attributes->except(['aria-describedby', 'aria-invalid', 'id', 'type', 'name', 'value'])->class([
                'block w-full min-h-touch rounded-md border bg-page px-3 text-base text-fg',
                'placeholder:text-fg-muted',
                'transition-colors duration-fast ease-standard',
                'disabled:bg-surface-alt disabled:opacity-disabled',
                'enabled:read-only:border-line enabled:read-only:bg-surface',
                'text-end' => $align === 'end',
                'border-line-strong hover:border-fg-secondary' => ! $errorId,
                'border-error' => (bool) $errorId,
            ]) }}
        />
    @endif

    @if ($hintId)
        <p id="{{ $hintId }}" class="text-sm break-words text-fg-secondary">{{ $hint }}</p>
    @endif

    @if ($errorId)
        <p id="{{ $errorId }}" class="flex items-start gap-1.5 text-sm text-error">
            <x-icon name="exclamation-circle" variant="mini" size="sm" class="mt-0.5" />
            <span class="min-w-0 break-words">{{ $error }}</span>
        </p>
    @endif
</div>
