{{--
    The one-time display of a new API key: the key in a selectable block and a
    Copy button that reads it from this block (the key is never put in
    JavaScript or Livewire state). The button is the first focusable element
    of the dialog; it has no tooltip, because a tooltip opened by that first
    focus would cover the key.
--}}
@php
    $key = isset($key) && is_string($key) ? $key : '';
@endphp

<div
    x-data="{ copied: false }"
    class="flex flex-col gap-3"
>
    <div class="flex flex-col gap-1">
        <span class="fi-fo-field-label-content text-sm font-medium text-fg">{{ __('api_keys.fields.key') }}</span>
        <code
            x-ref="key"
            class="font-numeric block select-all break-all rounded-md border border-line bg-sunken p-3 text-fg"
            data-issued-key
        >{{ $key }}</code>
    </div>

    <div>
        <x-filament::button
            color="gray"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocument"
            x-on:click="window.navigator.clipboard.writeText($refs.key.textContent.trim()).then(() => { copied = true; new FilamentNotification().title({{ \Illuminate\Support\Js::from(__('api_keys.issued.copied')) }}).success().send() }).catch(() => new FilamentNotification().title({{ \Illuminate\Support\Js::from(__('api_keys.issued.copy_failed')) }}).danger().send())"
            data-copy-issued-key
        >
            <span x-show="! copied">{{ __('api_keys.issued.copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('api_keys.issued.copied') }}</span>
        </x-filament::button>
    </div>
</div>
