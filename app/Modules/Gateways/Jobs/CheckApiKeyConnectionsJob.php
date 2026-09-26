<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Jobs;

use App\Modules\Gateways\Actions\CheckApiKeyConnectionHealth;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Tenancy\Contracts\TenantAware;
use App\Modules\Tenancy\Jobs\CapturesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Health check of one api_key connection (plan 12.3.3), dispatched daily by
 * `axispay:gateways:check-api-keys` inside the connection's tenant context.
 * The payload is the connection ID only: the key is decrypted when the job
 * runs, never serialized (plan 26.2 case 19).
 */
final class CheckApiKeyConnectionsJob implements ShouldQueue, TenantAware
{
    use CapturesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $connectionId)
    {
        $this->captureTenantContext();
    }

    public function handle(CheckApiKeyConnectionHealth $check): void
    {
        $connection = GatewayConnection::query()->find($this->connectionId);

        if ($connection !== null) {
            $check->handle($connection);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }
}
