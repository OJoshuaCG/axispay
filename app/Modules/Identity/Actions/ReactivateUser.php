<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Access\Services\RoleGrantGuard;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\CannotDeactivateUserException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reactivates a tenant user. Same rules as DeactivateUser: re-authentication
 * and the actor must hold every permission of the target (reactivating an
 * owner is an ownership decision).
 */
final readonly class ReactivateUser
{
    public function __construct(
        private RoleGrantGuard $grants,
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, User $target): User
    {
        Gate::forUser($actor)->authorize('reactivate', $target);
        $this->reauthentication->ensureConfirmed();

        return DB::transaction(function () use ($actor, $target): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if (! $locked->isDisabled()) {
                return $locked;
            }

            if (! $this->grants->canManage($actor, $locked)) {
                throw CannotDeactivateUserException::morePrivileged();
            }

            $locked->forceFill(['disabled_at' => null])->save();
            $this->audit->record(AuditAction::UserReactivated, $locked, actor: Actor::user($actor->id));

            return $locked;
        });
    }
}
