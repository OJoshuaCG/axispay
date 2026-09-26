<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Services;

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;

/**
 * Retention of incoming gateway events (plan 14.4, ADR-0047), across tenants
 * (rows are purged whatever tenant they belong to; unroutable ones have
 * none). On the scope-bypass whitelist (config/tenancy.php, ADR-0031). Only
 * deletes rows and shrinks payloads: it never reads them for business use.
 *
 *  - `ignored` and `unroutable` rows older than `ignored_retention_days`
 *    (7) are deleted;
 *  - `processed` and `failed` rows older than `processed_payload_days` (30)
 *    keep only the reduced payload (the gateway is the source of truth and
 *    is re-queried anyway, ADR-017).
 */
final readonly class ProviderEventRetention
{
    public function __construct(private GatewayFactory $gateways) {}

    /**
     * @return array{deleted: int, reduced: int}
     */
    public function purge(): array
    {
        $deleted = ProviderEvent::query()->withoutGlobalScopes()
            ->whereIn('status', [ProviderEventStatus::Ignored->value, ProviderEventStatus::Unroutable->value])
            ->where('received_at', '<', now()->subDays($this->days('ignored_retention_days', 7)))
            ->delete();

        $reduced = 0;

        ProviderEvent::query()->withoutGlobalScopes()
            ->select(['id', 'provider', 'payload'])
            ->whereIn('status', [ProviderEventStatus::Processed->value, ProviderEventStatus::Failed->value])
            ->where('received_at', '<', now()->subDays($this->days('processed_payload_days', 30)))
            ->where('payload', 'not like', '%"axispay_reduced":true%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$reduced): void {
                foreach ($rows as $row) {
                    $provider = $row->provider instanceof GatewayProvider ? $row->provider : GatewayProvider::Stripe;

                    // Builder update: no model events, the row's tenant is untouched.
                    ProviderEvent::query()->withoutGlobalScopes()->whereKey($row->id)
                        ->update(['payload' => $this->gateways->for($provider)->reduceWebhookPayload($row->payload)]);
                    $reduced++;
                }
            });

        return ['deleted' => is_int($deleted) ? $deleted : 0, 'reduced' => $reduced];
    }

    private function days(string $key, int $default): int
    {
        $days = config('axispay.gateways.stripe.provider_events.'.$key);

        return is_int($days) && $days > 0 ? $days : $default;
    }
}
