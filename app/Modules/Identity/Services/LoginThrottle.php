<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Filament\Pages\Login;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The per-account sign-in limiter of both panels (ADR-0034). Clearing it lets
 * a recovered account sign in right away instead of waiting for the decay.
 * Filament's per-IP limit is keyed by IP and is not affected.
 */
final class LoginThrottle
{
    /** Panel ids that use Identity\Filament\Pages\Login. */
    public const array PANELS = ['admin', 'app'];

    public function clear(string $email): void
    {
        foreach (self::PANELS as $panel) {
            RateLimiter::clear(Login::throttleKeyFor($panel, $email));
        }
    }
}
