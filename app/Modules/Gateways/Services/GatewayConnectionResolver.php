<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Services;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Ids\Ulid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cross-tenant lookups of gateway connections, for entry points that run
 * before a tenant is known (plan 6.3, 6.5): incoming webhooks, uniqueness
 * checks across tenants, the health-check scheduler and the read-only view
 * of the platform panel. On the scope-bypass whitelist (config/tenancy.php,
 * ADR-0031). Callers set the tenant context from the returned row before
 * doing any tenant work; nothing here writes.
 */
final class GatewayConnectionResolver
{
    /**
     * The connection an event of `$accountId` belongs to (plan 14.2 step 5):
     * the live one if any, otherwise the most recent disconnected one (a late
     * event is still routed, then ignored) — but only when every connection
     * of that account belongs to the same tenant: an event is never handed
     * to another tenant's old connection (ADR-0051). Payment events are
     * routed by their attempt first (forPaymentAttempt).
     */
    public function forProviderAccount(GatewayProvider $provider, string $accountId, bool $livemode): ?GatewayConnection
    {
        $connections = $this->unscoped()
            ->where('provider', $provider->value)
            ->where('provider_account_id', $accountId)
            ->where('livemode', $livemode)
            // active_slot is 1 while not disconnected, NULL after (NULLs sort last).
            ->orderByDesc('active_slot')
            ->orderByDesc('created_at')
            ->get();

        $first = $connections->first();

        if ($first === null || ! $first->status->isDisconnected()) {
            return $first;
        }

        return $connections->every(static fn (GatewayConnection $c): bool => $c->tenant_id === $first->tenant_id) ? $first : null;
    }

    /**
     * The connection of the attempt a payment event names in its metadata
     * (ADR-0051), when it matches the event's account and mode: the payment
     * belongs to the connection that created it, whatever its status now.
     */
    public function forPaymentAttempt(GatewayProvider $provider, string $attemptId, string $accountId, bool $livemode): ?GatewayConnection
    {
        if (! Ulid::isValid($attemptId)) {
            return null;
        }

        $connectionId = PaymentAttempt::query()->withoutGlobalScopes()->whereKey($attemptId)->value('gateway_connection_id');

        if (! is_string($connectionId)) {
            return null;
        }

        return $this->unscoped()
            ->whereKey($connectionId)
            ->where('provider', $provider->value)
            ->where('provider_account_id', $accountId)
            ->where('livemode', $livemode)
            ->first();
    }

    /** ADR-0051: whether the platform reaches this account through Connect (any status). */
    public function accountUsesConnect(GatewayProvider $provider, string $accountId, bool $livemode): bool
    {
        return $this->unscoped()
            ->where('provider', $provider->value)
            ->where('provider_account_id', $accountId)
            ->where('livemode', $livemode)
            ->whereIn('connection_method', array_map(static fn (ConnectionMethod $m): string => $m->value, array_filter(ConnectionMethod::cases(), static fn (ConnectionMethod $m): bool => $m->usesConnect())))
            ->exists();
    }

    /** The api_key connection behind `/webhooks/stripe/direct/{id}`. */
    public function forDirectWebhook(string $connectionId): ?GatewayConnection
    {
        if (! Ulid::isValid($connectionId)) {
            return null;
        }

        return $this->unscoped()
            ->whereKey($connectionId)
            ->where('connection_method', ConnectionMethod::ApiKey->value)
            ->first();
    }

    /** Plan 12.3.3 step 7: the same secret may not be stored twice. */
    public function fingerprintInUse(string $fingerprint, ?string $exceptConnectionId = null): bool
    {
        return $this->unscoped()
            ->where('credentials_fingerprint', $fingerprint)
            ->when($exceptConnectionId !== null, static fn (Builder $query): Builder => $query->whereKeyNot($exceptConnectionId))
            ->exists();
    }

    /**
     * Plan 7.4: a gateway account belongs to one tenant. Any row of another
     * tenant counts, disconnected ones included (ADR-0047).
     */
    public function accountLinkedToAnotherTenant(GatewayProvider $provider, string $accountId, bool $livemode, string $tenantId): bool
    {
        return $this->unscoped()
            ->where('provider', $provider->value)
            ->where('provider_account_id', $accountId)
            ->where('livemode', $livemode)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();
    }

    /**
     * api_key connections the daily health check visits (plan 12.3.3).
     *
     * @return Collection<int, GatewayConnection>
     */
    public function apiKeyConnectionsToCheck(): Collection
    {
        return $this->unscoped()
            ->select(['id', 'tenant_id', 'livemode'])
            ->where('connection_method', ConnectionMethod::ApiKey->value)
            ->whereIn('status', [ConnectionStatus::Active->value, ConnectionStatus::Restricted->value, ConnectionStatus::InvalidCredentials->value])
            ->orderBy('id')
            ->get();
    }

    /**
     * Rows whose credentials are encrypted with another key version than the
     * current one (key rotation, plan 23.2).
     *
     * @return Collection<int, GatewayConnection>
     */
    public function withCredentialsOnOldKeys(int $currentVersion): Collection
    {
        return $this->unscoped()
            ->select(['id', 'tenant_id', 'livemode'])
            ->whereNotNull('credentials_key_version')
            ->where('credentials_key_version', '!=', $currentVersion)
            ->orderBy('id')
            ->get();
    }

    /**
     * Every connection of a tenant, both modes, newest first (platform panel,
     * read-only; credential columns are hidden by the model).
     *
     * @return Collection<int, GatewayConnection>
     */
    public function ofTenant(string $tenantId): Collection
    {
        return $this->unscoped()->where('tenant_id', $tenantId)->orderByDesc('created_at')->get();
    }

    /**
     * @return Builder<GatewayConnection>
     */
    private function unscoped(): Builder
    {
        return GatewayConnection::query()->withoutGlobalScopes();
    }
}
