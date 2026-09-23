<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Test-only job that is NOT TenantAware but queries a tenant model: it must
 * fail (plan 26.2 case 17).
 */
final class UnscopedUsersJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        User::query()->count();
    }
}
