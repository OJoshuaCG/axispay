<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Services\LivemodeSelector;
use App\Modules\Tenancy\TenantContext;

/**
 * Switches the tenant panel between test and live data (plan 6.3). Audited,
 * and the context of the current request follows immediately.
 */
final readonly class SwitchLivemode
{
    public function __construct(
        private LivemodeSelector $selector,
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function handle(bool $livemode): void
    {
        $tenantId = $this->context->idOrFail();
        $previous = $this->selector->current();

        if ($previous === $livemode) {
            return;
        }

        $this->selector->set($livemode);
        $this->context->set($tenantId, $livemode);

        $this->audit->record(AuditAction::LivemodeSwitched, changes: [
            'before' => ['livemode' => $previous],
            'after' => ['livemode' => $livemode],
        ]);
    }
}
