{{-- Invitation acceptance (plan 17.3). Design-system components only. --}}
<x-layouts.app :title="__('identity.invitation.title')">
    <x-slot:header>
        <header class="mx-auto flex w-full max-w-narrow flex-wrap items-center justify-between gap-x-4 gap-y-2 px-gutter py-stack-lg">
            <span class="min-w-0 truncate font-semibold">{{ \App\Modules\Shared\Support\Brand::displayName() }}</span>
            <x-site-controls />
        </header>
    </x-slot:header>

    <div class="mx-auto w-full max-w-narrow px-gutter pb-section">
        <x-card as="section" padding="lg">
            <x-slot:header>
                <h1 class="text-2xl font-semibold">{{ __('identity.invitation.title') }}</h1>
            </x-slot:header>

            <p class="mb-stack-md text-fg-secondary break-words">{{ __('identity.invitation.intro', ['email' => $email]) }}</p>

            @if ($errors->any())
                <x-alert variant="error" :title="__('identity.invitation.error_title')" class="mb-stack-md" focus>
                    {{ __('identity.invitation.error_body') }}
                </x-alert>
            @endif

            <form method="POST" action="{{ request()->fullUrl() }}" class="grid gap-stack-md">
                @csrf
                <x-input name="name" :label="__('identity.invitation.name')" autocomplete="name" :value="old('name')" :error="$errors->first('name')" required />
                <x-input name="password" type="password" :label="__('identity.invitation.password')" :hint="__('identity.invitation.password_hint')" autocomplete="new-password" :error="$errors->first('password')" required />
                <x-input name="password_confirmation" type="password" :label="__('identity.invitation.password_confirmation')" autocomplete="new-password" required />
                <div>
                    <x-button type="submit" variant="primary">{{ __('identity.invitation.submit') }}</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
