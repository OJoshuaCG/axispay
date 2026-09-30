<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\TenantLogo;
use App\Modules\Branding\Services\TenantLogos;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes one variant of the merchant's logo (ADR-0056 part B). Removing the
 * dark variant keeps the light one (dark theme then shows the light logo on
 * a light plate). Removing the light logo also removes the dark one: a dark
 * variant alone is never shown, and without a logo the payment page shows
 * the merchant's name. Same guards and audit as UpdateTenantLogo. Removing a
 * variant that is not set is a no-op (no audit row).
 */
final readonly class RemoveTenantLogo
{
    public function __construct(
        private AuditLogger $audit,
        private TenantLogos $logos,
    ) {}

    /**
     * @return list<LogoVariant> the variants actually removed
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, LogoVariant $variant): array
    {
        Gate::forUser($actor)->authorize('manage', TenantLogo::class);

        $variants = $variant === LogoVariant::Light ? [LogoVariant::Light, LogoVariant::Dark] : [LogoVariant::Dark];

        $removed = DB::transaction(function () use ($actor, $variants): array {
            $removed = [];

            $logos = TenantLogo::query()
                ->select(TenantLogo::METADATA_COLUMNS)
                ->where('tenant_id', $actor->tenant_id)
                ->whereIn('variant', array_map(static fn (LogoVariant $v): string => $v->value, $variants))
                ->lockForUpdate()
                ->get();

            foreach ($logos as $logo) {
                $logo->delete();
                $removed[] = $logo->variant;

                $this->audit->record(AuditAction::TenantLogoRemoved, $logo, [
                    'variant' => $logo->variant->value,
                    'width' => $logo->width,
                    'height' => $logo->height,
                    'size_bytes' => $logo->size_bytes,
                    'sha256_hash' => $logo->sha256,
                ], tenantId: $actor->tenant_id, actor: Actor::user($actor->id));
            }

            return $removed;
        });

        $this->logos->forget($actor->tenant_id);

        return $removed;
    }
}
