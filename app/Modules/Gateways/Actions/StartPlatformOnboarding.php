<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Gateways\Stripe\Connection\PlatformOnboardingFlow;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Create or connect with Stripe (recommended)" (plan 12.3.1): creates the
 * connection and its connected account, then returns a hosted onboarding
 * link. Idempotent: a second call continues the same connection, and the
 * account's idempotency key is derived from the connection ID, so a retry
 * after a failure never creates a second Stripe account.
 *
 * The Stripe call runs outside any transaction or row lock.
 */
final readonly class StartPlatformOnboarding
{
    public function __construct(
        private PlatformOnboardingFlow $flow,
        private CreateOnboardingLink $links,
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
    ) {}

    /**
     * @return string the hosted onboarding URL to redirect to
     */
    public function handle(User $actor, string $country): string
    {
        Gate::forUser($actor)->authorize('manage', GatewayConnection::class);
        $this->reauthentication->ensureConfirmed();

        if (! ConnectionMethod::PlatformOnboarding->isEnabled()) {
            throw new GatewayConnectionException(ConnectionError::MethodDisabled);
        }

        $country = strtoupper(trim($country));

        if (! in_array($country, ApiKeyFlow::allowedCountries(), true)) {
            throw new GatewayConnectionException(ConnectionError::CountryNotAllowed);
        }

        $connection = $this->pendingConnection($country);

        if ($connection->provider_account_id === null) {
            $connection = $this->attachAccount($connection, $country);
        }

        return $this->links->handle($actor, $connection);
    }

    /**
     * The tenant's current connection for this mode, or a new one. Another
     * method already connected must be disconnected first (plan 12.3.4).
     */
    private function pendingConnection(string $country): GatewayConnection
    {
        $current = GatewayConnection::query()->current()->first();

        if ($current !== null) {
            return $current->connection_method === ConnectionMethod::PlatformOnboarding
                ? $current
                : throw new GatewayConnectionException(ConnectionError::AlreadyConnected);
        }

        try {
            $connection = new GatewayConnection;
            $connection->forceFill([
                'connection_method' => ConnectionMethod::PlatformOnboarding,
                'status' => ConnectionStatus::Onboarding,
                'country' => $country,
            ])->save();

            return $connection;
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first (one connection per mode).
            return $this->pendingConnection($country);
        }
    }

    private function attachAccount(GatewayConnection $connection, string $country): GatewayConnection
    {
        try {
            $accountId = $this->flow->createAccount($connection, $country);
        } catch (GatewayException $e) {
            throw new GatewayConnectionException(
                $e instanceof GatewayUnavailableException ? ConnectionError::GatewayUnavailable : ConnectionError::GatewayRefused,
                $e,
            );
        }

        return DB::transaction(function () use ($connection, $accountId, $country): GatewayConnection {
            $locked = GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->provider_account_id !== null) {
                return $locked;
            }

            $locked->forceFill(['provider_account_id' => $accountId])->save();

            $this->audit->record(AuditAction::GatewayOnboardingStarted, $locked, [
                'method' => ConnectionMethod::PlatformOnboarding->value,
                'provider_account_id' => $accountId,
                'country' => $country,
                'livemode' => $locked->livemode,
            ]);

            return $locked;
        });
    }
}
