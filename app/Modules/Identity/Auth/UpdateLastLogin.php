<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Events\Login;

/**
 * Keeps `last_login_at` of users and platform admins (plan 7.1, 7.2). An
 * impersonated sign-in is not the user's own login and is not recorded here.
 */
final readonly class UpdateLastLogin
{
    public function __construct(private ImpersonationState $impersonation) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User && ! $this->impersonation->isActive()) {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        } elseif ($user instanceof PlatformAdmin) {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        }
    }
}
