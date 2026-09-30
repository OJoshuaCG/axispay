{{--
    The one-time display of a signing secret (webhook endpoint or pre-payment
    validation URL; plan 15.1, 15.8.1): the secret in a selectable block and
    a Copy button that reads it from this block (the secret is never put in
    JavaScript or Livewire state). The button is the first focusable element
    of the dialog, without a tooltip that would cover the secret.

    Props: secret (string)
--}}
@php
    $secret = isset($secret) && is_string($secret) ? $secret : '';
@endphp

<div
    x-data="{ copied: false }"
    class="flex flex-col gap-3"
>
    <div class="flex flex-col gap-1">
        <span class="fi-fo-field-label-content text-sm font-medium text-fg">{{ __('webhooks.secret.label') }}</span>
        <code
            x-ref="secret"
            class="font-numeric block select-all break-all rounded-md border border-line bg-sunken p-3 text-fg"
            data-issued-secret
        >{{ $secret }}</code>
    </div>

    <div>
        <x-filament::button
            color="gray"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocument"
            x-on:click="window.navigator.clipboard.writeText($refs.secret.textContent.trim()).then(() => { copied = true; new FilamentNotification().title({{ \Illuminate\Support\Js::from(__('webhooks.secret.copied')) }}).success().send() }).catch(() => new FilamentNotification().title({{ \Illuminate\Support\Js::from(__('webhooks.secret.copy_failed')) }}).danger().send())"
            data-copy-issued-secret
        >
            <span x-show="! copied">{{ __('webhooks.secret.copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('webhooks.secret.copied') }}</span>
        </x-filament::button>
    </div>
</div>
