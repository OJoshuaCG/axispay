<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Shared\Http\RequestId;
use App\Modules\Shared\Logging\Redactor;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The only writer of audit_logs (plan 7.1). Entries are redacted with the same
 * Redactor as the logs, so secrets and PII never reach the audit trail.
 *
 * Tenant of the entry:
 *  - `platform: true`    -> platform event, `tenant_id` NULL;
 *  - `tenantId: '...'`   -> that tenant (e.g. platform actions on a tenant);
 *  - neither             -> the current tenant context (throws without one).
 */
final class AuditLogger
{
    private const int USER_AGENT_MAX = 512;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Redactor $redactor,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        AuditAction $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $tenantId = null,
        bool $platform = false,
        ?Actor $actor = null,
    ): AuditLog {
        $actor ??= $this->currentActor();
        $changes = $this->withImpersonation($changes);

        $entry = new AuditLog;
        $entry->forceFill([
            'tenant_id' => $platform ? null : ($tenantId ?? $this->context->idOrNull() ?? throw new MissingTenantContextException(AuditLog::class)),
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action->value,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $this->subjectId($subject),
            'changes' => $changes === [] ? null : $this->redact($changes),
            'ip' => $this->request()?->ip(),
            'user_agent' => $this->userAgent(),
            'request_id' => RequestId::current(),
        ]);
        $entry->save();

        return $entry;
    }

    /**
     * Redacts secrets and PII, but keeps SHA-256 digests under `*_hash` keys
     * intact: they are pseudonymous by design and the value scanner would
     * otherwise mistake a long digit run inside the hex for a card number.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function redact(array $changes): array
    {
        $digests = array_filter(
            $changes,
            static fn (mixed $value, string $key): bool => str_ends_with($key, '_hash') && is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        return [...$this->redactor->redactArray($changes), ...$digests];
    }

    public function currentActor(): Actor
    {
        $admin = $this->auth->guard('platform')->user();

        if ($admin instanceof PlatformAdmin) {
            return Actor::platformAdmin($admin->id);
        }

        $user = $this->auth->guard('web')->user();

        if ($user instanceof User) {
            return Actor::user($user->id);
        }

        return Actor::system();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function withImpersonation(array $changes): array
    {
        $request = $this->request();

        if ($request === null || ! $request->hasSession()) {
            return $changes;
        }

        $state = new ImpersonationState($request->session());

        if (! $state->isActive()) {
            return $changes;
        }

        return [...$changes, 'impersonation' => [
            'id' => $state->impersonationId(),
            'platform_admin_id' => $state->platformAdminId(),
        ]];
    }

    private function subjectId(?Model $subject): ?string
    {
        $key = $subject?->getKey();

        return is_string($key) || is_int($key) ? (string) $key : null;
    }

    private function request(): ?Request
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function userAgent(): ?string
    {
        $agent = $this->request()?->userAgent();

        return $agent === null ? null : mb_substr($agent, 0, self::USER_AGENT_MAX);
    }
}
