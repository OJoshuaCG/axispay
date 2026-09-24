<x-layouts.app class="flex min-h-dvh flex-col">
    <div class="mx-auto flex w-full max-w-content flex-1 flex-col px-gutter pt-safe-top pb-safe-bottom">
        <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-stack-lg">
            <span class="inline-flex min-w-0 items-center gap-2 text-lg font-semibold tracking-snug">
                <x-icon name="shield-check" size="lg" class="text-feature" />
                <span class="truncate">{{ \App\Modules\Shared\Support\Brand::displayName() }}</span>
            </span>

            <x-site-controls />
        </header>

        <section class="flex flex-1 flex-col items-start justify-center gap-stack-lg py-section">
            <x-badge variant="info" icon="clock">{{ __('welcome.badge') }}</x-badge>

            <h1 class="max-w-narrow text-5xl font-semibold text-balance break-words text-fg">
                {{ __('welcome.title') }}
            </h1>

            <p class="max-w-narrow text-lg text-fg-secondary">
                {{ __('welcome.lead') }}
            </p>
        </section>

        <footer class="border-t border-line py-stack-lg text-sm text-fg-secondary">
            {{ __('welcome.copyright', ['year' => date('Y'), 'app' => \App\Modules\Shared\Support\Brand::displayName()]) }}
        </footer>
    </div>
</x-layouts.app>
