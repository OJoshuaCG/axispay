<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Data\IssuedValidationEndpoint;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Services\ValidationEndpointNotifier;
use App\Modules\Webhooks\Services\WebhookSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Rotates the validation secret without downtime (plan 15.8.1, 15.5): the
 * current secret becomes the previous one and keeps signing for 24 hours
 * (both signatures are sent), and a new one is generated and returned ONCE.
 * `webhooks:manage` + re-authentication (plan 17.3). Rotating again within
 * the 24 hours drops the oldest secret.
 */
final readonly class RotateValidationEndpointSecret
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private TenantAccess $access,
        private WebhookSigner $signer,
        private AuditLogger $audit,
        private ValidationEndpointNotifier $notifier,
    ) {}

    public function handle(User $actor, ValidationEndpoint $endpoint): IssuedValidationEndpoint
    {
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('update', $endpoint);
        $this->reauthentication->ensureConfirmed();

        $secret = $this->signer->generateSecret();

        $rotated = DB::transaction(function () use ($actor, $endpoint, $secret): ValidationEndpoint {
            $locked = ValidationEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);
            $expiresAt = CarbonImmutable::now()->addHours(max(0, config()->integer('axispay.pre_payment_validation.previous_secret_hours')));

            $locked->forceFill([
                'previous_secret' => $locked->secret,
                'previous_secret_expires_at' => $expiresAt,
                'secret' => $secret,
            ])->save();

            $this->audit->record(AuditAction::ValidationEndpointSecretRotated, $locked, [
                'host' => $locked->host(),
                'livemode' => $locked->livemode,
                'previous_secret_expires_at' => $expiresAt->toIso8601ZuluString(),
            ], actor: Actor::user($actor->id));

            return $locked;
        });

        $this->notifier->notify($rotated, ValidationEndpointChange::SecretRotated);

        return new IssuedValidationEndpoint($rotated, $secret, false);
    }
}
