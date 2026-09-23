<?php

declare(strict_types=1);

namespace App\Modules\Audit\Listeners;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Events\PlatformContextEntered;

/**
 * Every entry into the platform context is audited (plan 6.1).
 */
final readonly class RecordPlatformContextEntry
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(PlatformContextEntered $event): void
    {
        $this->audit->record(
            AuditAction::PlatformContextEntered,
            changes: array_filter([
                'reason' => $event->reason,
                'previous_tenant_id' => $event->previousTenantId,
            ], static fn (?string $value): bool => $value !== null),
            platform: true,
        );
    }
}
