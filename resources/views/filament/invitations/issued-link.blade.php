{{--
    The one-time display of an invitation's accept link (ADR-0068): the link
    in a selectable block and a Copy button that reads it from this block (the
    link is never put in JavaScript or Livewire state). The button is the
    first focusable element of the dialog, without a tooltip that would cover
    the link.

    Props: link (string)
--}}
@php
    $link = isset($link) && is_string($link) ? $link : '';
@endphp

<div
    x-data="{ copied: false }"
    class="flex flex-col gap-3"
>
    <div class="flex flex-col gap-1">
        <span class="fi-fo-field-label-content text-sm font-medium text-fg">{{ __('platform.tenants.invitations.link.label') }}</span>
        <code
            x-ref="link"
            class="font-numeric block select-all break-all rounded-md border border-line bg-sunken p-3 text-fg"
            data-issued-invitation-link
        >{{ $link }}</code>
    </div>

    <div>
        <x-filament::button
            color="gray"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocument"
            x-on:click="window.navigator.clipboard.writeText($refs.link.textContent.trim()).then(() => { copied = true; new FilamentNotification().title({{ \Illuminate\Support\Js::from(__('platform.tenants.invitations.link.copied')) }}).success().send() }).catch(() => new FilamentNotification().title({{ \Illuminate\Support\Js::from(__('platform.tenants.invitations.link.copy_failed')) }}).danger().send())"
            data-copy-issued-invitation-link
        >
            <span x-show="! copied">{{ __('platform.tenants.invitations.link.copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('platform.tenants.invitations.link.copied') }}</span>
        </x-filament::button>
    </div>
</div>
