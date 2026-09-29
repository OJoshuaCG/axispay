{{-- Expired, used, revoked or unknown invitation. Deliberately uniform (no enumeration). --}}
<x-layouts.app :title="__('identity.invitation.invalid_title')">
    <x-slot:header>
        <header class="mx-auto flex w-full max-w-narrow flex-wrap items-center justify-end gap-x-4 gap-y-2 px-gutter pt-stack-lg">
            <x-site-controls />
        </header>
        {{-- The platform brand at sign-in size, like the panels' sign-in page (ADR-0054). --}}
        <x-platform-brand class="mx-auto w-full max-w-narrow px-gutter py-stack-lg" />
    </x-slot:header>

    <div class="mx-auto w-full max-w-narrow px-gutter pb-section">
        <x-alert variant="warning" :title="__('identity.invitation.invalid_title')" focus>
            {{ __('identity.invitation.invalid_body') }}
        </x-alert>
    </div>
</x-layouts.app>
