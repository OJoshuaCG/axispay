<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Actions;

use App\Modules\ApiKeys\Data\CreateApiKeyData;
use App\Modules\ApiKeys\Data\IssuedApiKey;
use App\Modules\ApiKeys\Enums\ApiKeyRefusal;
use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Exceptions\ApiKeyNotAllowedException;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Notifications\LiveApiKeyCreatedNotification;
use App\Modules\ApiKeys\Services\ApiKeyGenerator;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\Services\TenantOwners;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Issues an API key for the current tenant in the current mode (plan 10.2,
 * 17.3): `api_keys:manage` + re-authentication. The plaintext is returned
 * once and never stored; the audit log keeps name, scopes, mode and the
 * last four characters only (log redaction masks anything shaped like a key). Creating a LIVE key e-mails every owner.
 */
final readonly class CreateApiKey
{
    public const int NAME_MAX = 100;

    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private ApiKeyGenerator $generator,
        private TenantContext $context,
        private AuditLogger $audit,
        private TenantOwners $owners,
        private TenantAccess $access,
    ) {}

    public function handle(User $actor, CreateApiKeyData $data): IssuedApiKey
    {
        // Plan 21.3 / ADR-013: a suspended or closed tenant's panel is read-only.
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new ApiKeyNotAllowedException(ApiKeyRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('create', ApiKey::class);
        $this->reauthentication->ensureConfirmed();

        $name = trim($data->name);
        $scopes = array_values(array_unique(array_map(static fn (ApiScope $scope): string => $scope->value, $data->scopes)));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new ApiKeyNotAllowedException(ApiKeyRefusal::InvalidName);
        }

        if ($scopes === []) {
            throw new ApiKeyNotAllowedException(ApiKeyRefusal::NoScopes);
        }

        // Keep the catalog order, whatever order the form sent.
        $scopes = array_values(array_intersect(ApiScope::values(), $scopes));
        $livemode = $this->context->livemode();
        $generated = $this->generator->generate($livemode);

        $key = DB::transaction(function () use ($actor, $name, $scopes, $livemode, $generated): ApiKey {
            $key = new ApiKey;
            $key->forceFill([
                'livemode' => $livemode,
                'name' => $name,
                'key_prefix' => $generated->prefix,
                'key_last4' => $generated->last4,
                'key_hash' => $generated->hash,
                'scopes' => $scopes,
                'created_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::ApiKeyCreated, $key, [
                'name' => $name,
                'scopes' => $scopes,
                'livemode' => $livemode,
                'key_last4' => $generated->last4,
            ], actor: Actor::user($actor->id));

            return $key;
        });

        if ($livemode) {
            $tenant = Tenant::query()->findOrFail($key->tenant_id);
            Notification::send($this->owners->of($tenant), new LiveApiKeyCreatedNotification($name, $actor->name));
        }

        return new IssuedApiKey($key, $generated->plaintext);
    }
}
