<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\Connection\PlatformOnboardingFlow;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Shared\Ids\Ulid;
use Illuminate\Support\Facades\Gate;

/**
 * A fresh hosted onboarding link for a platform_onboarding connection (plan
 * 12.3.1 steps 2, 4 and 5: "Continue onboarding" and the refresh URL). A
 * link lets whoever opens it enter the account's bank details, so it needs
 * `gateway:manage` and the re-authentication window (plan 17.3).
 */
final readonly class CreateOnboardingLink
{
    public function __construct(
        private PlatformOnboardingFlow $flow,
        private ReauthenticationWindow $reauthentication,
    ) {}

    public function handle(User $actor, GatewayConnection $connection): string
    {
        Gate::forUser($actor)->authorize('manage', $connection);
        $this->reauthentication->ensureConfirmed();

        if ($connection->connection_method !== ConnectionMethod::PlatformOnboarding
            || $connection->provider_account_id === null
            || ! in_array($connection->status, [ConnectionStatus::Onboarding, ConnectionStatus::Restricted, ConnectionStatus::Active], true)) {
            throw new GatewayConnectionException(ConnectionError::NotOnboarding);
        }

        try {
            return $this->flow->createOnboardingLink(
                $connection,
                returnUrl: route('gateways.stripe.onboarding.return', ['connection' => $connection->id]),
                refreshUrl: route('gateways.stripe.onboarding.refresh', ['connection' => $connection->id]),
                operationId: Ulid::generate(),
            );
        } catch (GatewayException $e) {
            throw new GatewayConnectionException(
                $e instanceof GatewayUnavailableException ? ConnectionError::GatewayUnavailable : ConnectionError::GatewayRefused,
                $e,
            );
        }
    }
}
