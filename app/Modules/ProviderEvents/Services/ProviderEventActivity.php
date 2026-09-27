<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Services;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * When gateway events last arrived (ADR-0050: incoming webhooks stay
 * mandatory, reconciliation is only a safety net). Read-only and across
 * tenants, for `axispay:doctor` and the platform panel: on the scope-bypass
 * whitelist (config/tenancy.php, ADR-0031). It reads timestamps and counts
 * only, never payloads, and dispatches nothing, so the doctor stays a pure
 * read (no platform-context audit row).
 *
 * "Silent": a connection that can charge, connected more than
 * `silence_warning_days` ago, with no event in that window. A quiet account
 * can be silent too; the check is a hint, not a failure.
 */
final readonly class ProviderEventActivity
{
    private const int DEFAULT_SILENCE_DAYS = 7;

    public static function silenceDays(): int
    {
        $days = config('axispay.gateways.stripe.provider_events.silence_warning_days');

        return is_int($days) && $days > 0 ? $days : self::DEFAULT_SILENCE_DAYS;
    }

    public function silenceThreshold(): CarbonImmutable
    {
        return now()->toImmutable()->subDays(self::silenceDays());
    }

    /** Latest event of the mode, routed or not. */
    public function lastReceivedAt(bool $livemode, GatewayProvider $provider = GatewayProvider::Stripe): ?CarbonImmutable
    {
        return self::date($this->events()
            ->where('provider', $provider->value)
            ->where('livemode', $livemode)
            ->max('received_at'));
    }

    /** A platform_onboarding or oauth connection that is not disconnected exists in the mode. */
    public function hasConnectConnections(bool $livemode): bool
    {
        return $this->connections()
            ->whereIn('connection_method', self::connectMethods())
            ->where('status', '<>', ConnectionStatus::Disconnected->value)
            ->where('livemode', $livemode)
            ->exists();
    }

    /** platform_onboarding and oauth connections of the mode that can charge. */
    public function chargeableConnectConnections(bool $livemode): int
    {
        return $this->connections()
            ->whereIn('connection_method', self::connectMethods())
            ->where('status', ConnectionStatus::Active->value)
            ->where('livemode', $livemode)
            ->count();
    }

    /**
     * An event reached the platform's Connect endpoint of the mode since the
     * date: routed to a Connect connection, or unroutable (only the Connect
     * endpoint stores those; the direct endpoint refuses unknown connections).
     */
    public function connectEventSince(bool $livemode, CarbonImmutable $since): bool
    {
        $connectMethods = self::connectMethods();

        return $this->events()
            ->where('provider', GatewayProvider::Stripe->value)
            ->where('livemode', $livemode)
            ->where('received_at', '>=', $since)
            ->where(function (Builder $query) use ($connectMethods): void {
                $query->whereNull('gateway_connection_id')
                    ->orWhereExists($this->connections()
                        ->whereColumn('gateway_connections.tenant_id', 'provider_events.tenant_id')
                        ->whereColumn('gateway_connections.id', 'provider_events.gateway_connection_id')
                        ->whereIn('gateway_connections.connection_method', $connectMethods));
            })
            ->exists();
    }

    /**
     * api_key connections of the mode that can charge, were connected before
     * the date and received no event since. Each has its own endpoint on the
     * merchant account, so they are checked one by one.
     */
    public function silentApiKeyConnections(bool $livemode, CarbonImmutable $since): int
    {
        return $this->connections()
            ->where('connection_method', ConnectionMethod::ApiKey->value)
            ->where('status', ConnectionStatus::Active->value)
            ->where('livemode', $livemode)
            ->where(static function (Builder $query) use ($since): void {
                $query->where('connected_at', '<', $since)
                    ->orWhere(static function (Builder $query) use ($since): void {
                        $query->whereNull('connected_at')->where('created_at', '<', $since);
                    });
            })
            ->whereNotExists($this->events()
                ->whereColumn('provider_events.tenant_id', 'gateway_connections.tenant_id')
                ->whereColumn('provider_events.gateway_connection_id', 'gateway_connections.id')
                ->where('provider_events.received_at', '>=', $since))
            ->count();
    }

    /**
     * Latest event of each connection of one tenant (platform panel).
     *
     * @param  list<string>  $connectionIds
     * @return array<string, CarbonImmutable> keyed by connection ID; connections without events are absent
     */
    public function lastReceivedAtByConnection(string $tenantId, array $connectionIds): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $rows = $this->events()
            ->where('tenant_id', $tenantId)
            ->whereIn('gateway_connection_id', $connectionIds)
            ->groupBy('gateway_connection_id')
            ->selectRaw('gateway_connection_id, MAX(received_at) AS last_received_at')
            ->pluck('last_received_at', 'gateway_connection_id');

        $dates = [];

        foreach ($rows as $connectionId => $value) {
            $date = self::date($value);

            if (is_string($connectionId) && $date !== null) {
                $dates[$connectionId] = $date;
            }
        }

        return $dates;
    }

    /** See the class comment. A connection connected inside the window is not silent yet. */
    public function isSilent(GatewayConnection $connection, ?CarbonImmutable $lastReceivedAt): bool
    {
        if (! $connection->status->canCharge()) {
            return false;
        }

        $since = $this->silenceThreshold();
        $connectedAt = $connection->connected_at ?? $connection->created_at;

        if ($connectedAt !== null && $connectedAt->greaterThanOrEqualTo($since)) {
            return false;
        }

        return $lastReceivedAt === null || $lastReceivedAt->lessThan($since);
    }

    /**
     * @return list<string>
     */
    private static function connectMethods(): array
    {
        return array_values(array_map(
            static fn (ConnectionMethod $method): string => $method->value,
            array_filter(ConnectionMethod::cases(), static fn (ConnectionMethod $method): bool => $method->usesConnect()),
        ));
    }

    /**
     * @return Builder<ProviderEvent>
     */
    private function events(): Builder
    {
        return ProviderEvent::query()->withoutGlobalScopes();
    }

    /**
     * @return Builder<GatewayConnection>
     */
    private function connections(): Builder
    {
        return GatewayConnection::query()->withoutGlobalScopes();
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }
}
