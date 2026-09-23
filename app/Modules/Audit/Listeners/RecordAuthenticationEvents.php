<?php

declare(strict_types=1);

namespace App\Modules\Audit\Listeners;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;

/**
 * Audits logins, failed logins and logouts on both guards (plan 7.1, 23.1).
 * Failed attempts never store the submitted e-mail: only a SHA-256 of the
 * normalized identifier, enough to correlate attempts without keeping PII.
 */
final readonly class RecordAuthenticationEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->recordFor($event->user, AuditAction::Login, ['guard' => $event->guard]);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user !== null) {
            $this->recordFor($event->user, AuditAction::Logout, ['guard' => $event->guard]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $identifier = $event->credentials['email'] ?? null;
        $changes = [
            'guard' => $event->guard,
            'login_hash' => is_string($identifier) ? hash('sha256', mb_strtolower(trim($identifier))) : null,
        ];

        if ($event->user instanceof User) {
            $this->audit->record(AuditAction::LoginFailed, $event->user, $changes, tenantId: $event->user->tenant_id, actor: Actor::system());

            return;
        }

        $this->audit->record(AuditAction::LoginFailed, $event->user instanceof PlatformAdmin ? $event->user : null, $changes, platform: true, actor: Actor::system());
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordFor(Authenticatable $user, AuditAction $action, array $changes): void
    {
        if ($user instanceof User) {
            $this->audit->record($action, $user, $changes, tenantId: $user->tenant_id, actor: Actor::user($user->id));

            return;
        }

        if ($user instanceof PlatformAdmin) {
            $this->audit->record($action, $user, $changes, platform: true, actor: Actor::platformAdmin($user->id));
        }
    }
}
