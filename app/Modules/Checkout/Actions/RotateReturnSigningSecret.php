<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Checkout\Services\ReturnSigningSecrets;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * Rotates the secret that signs the return proof of the tenant's links
 * (ADR-0064) in the panel's current mode: the new secret is returned ONCE and
 * the one it replaces keeps signing for 24 hours. `settings:manage` on a
 * writable panel (the payment settings policy) plus re-authentication, like
 * the validation secret. Audited without any secret.
 */
final readonly class RotateReturnSigningSecret
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private ReturnSigningSecrets $secrets,
        private AuditLogger $audit,
        private TenantContext $context,
    ) {}

    /**
     * @return string the new secret, in plaintext, to show once
     */
    public function handle(User $actor): string
    {
        Gate::forUser($actor)->authorize('manage', TenantSettings::class);
        $this->reauthentication->ensureConfirmed();

        $secret = $this->secrets->rotate();

        $this->audit->record(AuditAction::ReturnSecretRotated, Tenant::query()->findOrFail($actor->tenant_id), [
            'livemode' => $this->context->livemode(),
            'previous_secret_expires_at' => $this->secrets->current()->previous_secret_expires_at?->toIso8601ZuluString(),
        ], tenantId: $actor->tenant_id, actor: Actor::user($actor->id));

        return $secret;
    }
}
