<?php

declare(strict_types=1);

namespace App\Modules\Identity\Filament\Concerns;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Filament\Facades\Filament;

/**
 * The signed-in tenant user and the current test/live mode, for tenant panel
 * pages and resources (plan 6.3).
 */
final class TenantPanel
{
    private function __construct() {}

    public static function user(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public static function livemode(): bool
    {
        return app(TenantContext::class)->livemodeOrNull() ?? false;
    }

    /** `live` or `test`: the suffix of mode-dependent translation keys. */
    public static function modeKey(): string
    {
        return self::livemode() ? 'live' : 'test';
    }
}
