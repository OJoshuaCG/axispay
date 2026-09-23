<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\LivemodeSelector;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant panel surface (plan 6.3): after authentication, the tenant comes
 * from the signed-in user and the mode from the session's test/live selector.
 * Runs on every panel request, Livewire updates included (persistent).
 */
final readonly class ResolveTenantContext
{
    public function __construct(
        private TenantContext $context,
        private LivemodeSelector $livemode,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
            abort(403);
        }

        $this->context->set($user->tenant_id, $this->livemode->current());

        return $next($request);
    }
}
