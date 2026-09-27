<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions\Concerns;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyValidationResult;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Shared steps of connecting and rotating merchant keys (plan 12.3.3): the
 * guard (permission, re-authentication, method enabled, risk notice), the
 * Stripe checks, uniqueness across tenants and the live-mode confirmation
 * of excessive permissions. A refusal is audited with its reason only.
 *
 * The using action has these dependencies: ApiKeyFlow $flow,
 * GatewayConnectionResolver $resolver, ReauthenticationWindow
 * $reauthentication, AuditLogger $audit and TenantContext $context.
 */
trait ValidatesApiKeys
{
    private function guard(User $actor, ApiKeyConnectionData $data, ?GatewayConnection $connection = null): void
    {
        Gate::forUser($actor)->authorize('manage', $connection ?? GatewayConnection::class);
        $this->reauthentication->ensureConfirmed();

        if (! ConnectionMethod::ApiKey->isEnabled()) {
            throw new GatewayConnectionException(ConnectionError::MethodDisabled);
        }

        if (! $data->riskAcknowledged) {
            throw new GatewayConnectionException(ConnectionError::RiskNotAcknowledged);
        }
    }

    /**
     * @throws ApiKeyValidationException
     */
    private function validateKeys(ApiKeyConnectionData $data, string $operationId, ?GatewayConnection $existing = null): ApiKeyValidationResult
    {
        $livemode = $this->context->livemode();

        try {
            $result = $this->flow->validate($data->credentials, $livemode, $operationId);

            if ($existing !== null && $result->account->providerAccountId !== $existing->provider_account_id) {
                throw new ApiKeyValidationException(ApiKeyRejection::DifferentAccount);
            }

            if ($this->resolver->fingerprintInUse($data->credentials->fingerprint(), $existing?->id)) {
                throw new ApiKeyValidationException(ApiKeyRejection::KeyAlreadyLinked);
            }

            if ($this->resolver->accountLinkedToAnotherTenant(GatewayProvider::Stripe, $result->account->providerAccountId, $livemode, $this->context->idOrFail())) {
                throw new ApiKeyValidationException(ApiKeyRejection::AccountAlreadyLinked);
            }

            // ADR-0051: an account the platform reaches through Connect keeps
            // sending its events to the Connect endpoint, even after the
            // Connect connection is disconnected; the same event would then
            // arrive through two routes. One account, one method.
            if ($this->resolver->accountUsesConnect(GatewayProvider::Stripe, $result->account->providerAccountId, $livemode)) {
                throw new ApiKeyValidationException(ApiKeyRejection::AccountUsesConnect);
            }

            if ($livemode && $result->excessive !== [] && ! $data->acceptExcessivePermissions) {
                throw new ApiKeyValidationException(ApiKeyRejection::ExcessivePermissionsNotConfirmed, $result->excessive);
            }

            return $result;
        } catch (ApiKeyValidationException $e) {
            $this->recordRejection($e, $existing);

            throw $e;
        }
    }

    private function recordRejection(ApiKeyValidationException $e, ?GatewayConnection $existing = null): void
    {
        $this->audit->record(AuditAction::GatewayCredentialsRejected, $existing, [
            'reason' => $e->rejection->value,
            'details' => $e->details,
            'livemode' => $this->context->livemode(),
        ]);
    }
}
