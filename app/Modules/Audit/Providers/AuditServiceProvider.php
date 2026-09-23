<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Listeners\RecordAuthenticationEvents;
use App\Modules\Audit\Listeners\RecordPlatformContextEntry;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Policies\AuditLogPolicy;
use App\Modules\Tenancy\Events\PlatformContextEntered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        Event::listen(PlatformContextEntered::class, RecordPlatformContextEntry::class);
        Event::subscribe(RecordAuthenticationEvents::class);
    }
}
