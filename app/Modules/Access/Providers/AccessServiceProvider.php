<?php

declare(strict_types=1);

namespace App\Modules\Access\Providers;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Policies\RolePolicy;
use App\Modules\Access\Policies\UserPolicy;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}
