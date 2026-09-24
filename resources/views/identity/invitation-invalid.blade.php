{{-- Expired, used, revoked or unknown invitation. Deliberately uniform (no enumeration). --}}
<x-layouts.app :title="__('identity.invitation.invalid_title')">
    <x-slot:header>
        <header class="mx-auto flex w-full max-w-narrow flex-wrap items-center justify-between gap-x-4 gap-y-2 px-gutter py-stack-lg">
            <span class="min-w-0 truncate font-semibold">{{ \App\Modules\Shared\Support\Brand::displayName() }}</span>
            <x-site-controls />
        </header>
    </x-slot:header>

    <div class="mx-auto w-full max-w-narrow px-gutter pb-section">
        <x-alert variant="warning" :title="__('identity.invitation.invalid_title')" focus>
            {{ __('identity.invitation.invalid_body') }}
        </x-alert>
    </div>
</x-layouts.app>
