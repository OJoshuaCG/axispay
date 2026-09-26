<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Actions;

use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Notifications\LiveApiKeyRevokedNotification;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantOwners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Revokes an API key (plan 10.2, 17.3): `api_keys:manage` +
 * re-authentication. Immediate and final: the next request with the key is
 * `401 invalid_api_key`. Revoking an already revoked key changes nothing.
 * Allowed for suspended and closed tenants too: it only removes access
 * (ADR-0048). Revoking a LIVE key e-mails every owner (plan 17.3, 22).
 */
final readonly class RevokeApiKey
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private TenantOwners $owners,
    ) {}

    public function handle(User $actor, ApiKey $key): ApiKey
    {
        Gate::forUser($actor)->authorize('revoke', $key);
        $this->reauthentication->ensureConfirmed();

        [$revoked, $changed] = DB::transaction(function () use ($actor, $key): array {
            $locked = ApiKey::query()->lockForUpdate()->findOrFail($key->id);

            if ($locked->isRevoked()) {
                return [$locked, false];
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::ApiKeyRevoked, $locked, [
                'name' => $locked->name,
                'livemode' => $locked->livemode,
                'key_last4' => $locked->key_last4,
            ], actor: Actor::user($actor->id));

            return [$locked, true];
        });

        if ($changed && $revoked->livemode) {
            $tenant = Tenant::query()->findOrFail($revoked->tenant_id);
            Notification::send($this->owners->of($tenant), new LiveApiKeyRevokedNotification($revoked->name, $actor->name));
        }

        return $revoked;
    }
}
