<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Http\Middleware;

use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\PlatformAdmin\Actions\EndImpersonation;
use App\Modules\PlatformAdmin\Enums\ImpersonationEndReason;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant panel: while a session is an impersonation, every request checks
 * that the impersonation is still active (not stopped, within 30 minutes) and
 * that the platform admin still exists, is enabled and is a superadmin.
 * Otherwise the session is ended, audited and signed out.
 */
final readonly class EnforceImpersonationWindow
{
    public function __construct(
        private ImpersonationState $state,
        private EndImpersonation $end,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $impersonationId = $this->state->impersonationId();

        if ($impersonationId === null) {
            return $next($request);
        }

        $session = ImpersonationSession::query()->find($impersonationId);

        $valid = $session !== null
            && $session->user_id === $request->user('web')?->getAuthIdentifier()
            && $this->adminStillQualifies($session);

        if ($valid && $session->isActive()) {
            return $next($request);
        }

        $this->end->handle($impersonationId, $valid ? ImpersonationEndReason::Expired : ImpersonationEndReason::Invalid);
        $this->state->forget();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(route('filament.app.auth.login'));
    }

    /**
     * The platform admin must still exist, be enabled and still be allowed to
     * impersonate (superadmin) on every request, not only at start.
     */
    private function adminStillQualifies(ImpersonationSession $session): bool
    {
        $admin = PlatformAdmin::query()->find($session->platform_admin_id);

        return $admin !== null && $admin->disabled_at === null && $admin->isSuperadmin();
    }
}
