<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Exceptions\InvitationNotAllowedException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-tenant invitation throttle (ADR-0034): every attempt counts, including
 * refused ones. Shared by new invitations and resends (ADR-0043), so a
 * resend loop cannot bypass the limit.
 */
final class InvitationThrottle
{
    /**
     * @throws InvitationNotAllowedException when the tenant is over the limit
     */
    public function hit(string $tenantId): void
    {
        $key = 'invitations:'.$tenantId;
        $max = config('axispay.invitations.max_per_hour', 20);

        if (RateLimiter::tooManyAttempts($key, is_int($max) ? $max : 20)) {
            throw InvitationNotAllowedException::throttled();
        }

        RateLimiter::hit($key, 3600);
    }
}
