<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant panel: 2FA is mandatory for users holding a sensitive permission
 * (plan 17.3) and recommended for the rest. Users who need it are sent to
 * Filament's 2FA set-up page until it is enabled. An impersonation session is
 * exempt: the platform admin authenticated with their own mandatory 2FA and
 * cannot enroll a factor for the user.
 */
final readonly class RequireTwoFactorForSensitiveUsers
{
    public function __construct(
        private EnsureMultiFactorAuthenticationIsEnabled $filament,
        private ImpersonationState $impersonation,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user('web');

        if (! $user instanceof User || $this->impersonation->isActive() || ! $user->requiresTwoFactor()) {
            return $next($request);
        }

        return $this->filament->handle($request, $next);
    }
}
