{{--
    Tenant panel banners: impersonation (plan 17.4, always visible while it
    lasts) and tenant status notices (plan 21.3).
--}}
@php
    $impersonation = app(\App\Modules\Identity\Services\ImpersonationState::class);
    $user = auth('web')->user();
    $tenant = $user instanceof \App\Modules\Identity\Models\User ? $user->tenant : null;
    $session = $impersonation->isActive()
        ? \App\Modules\PlatformAdmin\Models\ImpersonationSession::query()->find($impersonation->impersonationId())
        : null;
@endphp

@if ($session !== null && $user !== null)
    <div class="pl-banner" data-tone="error" role="status">
        <p class="min-w-0 break-words">
            {{ __('platform.impersonation.banner', ['name' => $user->name, 'time' => $session->expires_at->setTimezone($tenant?->timezone ?? 'UTC')->isoFormat('LT')]) }}
        </p>
        <form method="POST" action="{{ route('impersonation.stop') }}">
            @csrf
            <button type="submit" class="pl-banner-action">{{ __('platform.impersonation.stop') }}</button>
        </form>
    </div>
@endif

@if ($tenant !== null && $tenant->status->showsPanelBanner())
    <div class="pl-banner" data-tone="{{ $tenant->status === \App\Modules\Tenancy\Enums\TenantStatus::Suspended ? 'warning' : 'info' }}" role="status">
        <p class="min-w-0 break-words">{{ __('tenancy.banner.'.$tenant->status->value) }}</p>
    </div>
@endif
