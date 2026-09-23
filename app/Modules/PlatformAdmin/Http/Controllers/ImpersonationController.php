<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\PlatformAdmin\Actions\ConsumeImpersonationToken;
use App\Modules\PlatformAdmin\Actions\EndImpersonation;
use App\Modules\PlatformAdmin\Enums\ImpersonationEndReason;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\TenantResource;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * App-host side of impersonation (plan 17.4): redeems the signed, single-use
 * hand-off link and ends the session. The admin host and the app host never
 * share a session cookie, hence the hand-off.
 */
final class ImpersonationController
{
    public function consume(Request $request, string $token, ConsumeImpersonationToken $consume, ImpersonationState $state): RedirectResponse
    {
        try {
            $session = $consume->handle($token);
        } catch (ImpersonationNotAllowedException) {
            abort(404);
        }

        $user = User::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $session->tenant_id)->findOrFail($session->user_id);

        // Drop whatever this browser had on the app host before, then sign in as the user.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $state->start($session->id, $session->platform_admin_id);
        Auth::guard('web')->login($user);

        return redirect()->to(route('filament.app.pages.dashboard'));
    }

    public function stop(Request $request, ImpersonationState $state, EndImpersonation $end): RedirectResponse
    {
        $impersonationId = $state->impersonationId();
        $tenantId = null;

        if ($impersonationId !== null) {
            $tenantId = ImpersonationSession::query()->withoutGlobalScope(TenantScope::class)->whereKey($impersonationId)->value('tenant_id');
            $end->handle($impersonationId, ImpersonationEndReason::Stopped);
        }

        $state->forget();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return is_string($tenantId)
            ? redirect()->away(TenantResource::getUrl('view', ['record' => $tenantId], panel: 'admin'))
            : redirect()->to(route('filament.app.auth.login'));
    }
}
