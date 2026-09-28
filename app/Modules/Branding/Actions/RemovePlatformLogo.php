<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes one variant of the platform logo (ADR-0053). Removing the light
 * logo also removes the dark one: a dark variant alone is never shown, and
 * without a logo the brand falls back to the name. Same guards and audit as
 * UpdatePlatformLogo. Removing a variant that is not set is a no-op (no
 * audit row).
 */
final readonly class RemovePlatformLogo
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private PlatformBrand $brand,
    ) {}

    /**
     * @return list<LogoVariant> the variants actually removed
     *
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     */
    public function handle(PlatformAdmin $actor, LogoVariant $variant): array
    {
        Gate::forUser($actor)->authorize('manage', PlatformLogo::class);
        $this->reauthentication->ensureConfirmed();

        $variants = $variant === LogoVariant::Light ? [LogoVariant::Light, LogoVariant::Dark] : [LogoVariant::Dark];

        $removed = DB::transaction(function () use ($actor, $variants): array {
            $removed = [];

            $logos = PlatformLogo::query()
                ->whereIn('variant', array_map(static fn (LogoVariant $v): string => $v->value, $variants))
                ->lockForUpdate()
                ->get();

            foreach ($logos as $logo) {
                $logo->delete();
                $removed[] = $logo->variant;

                $this->audit->record(AuditAction::PlatformLogoRemoved, $logo, [
                    'variant' => $logo->variant->value,
                    'sha256_hash' => $logo->sha256,
                ], platform: true, actor: Actor::platformAdmin($actor->id));
            }

            return $removed;
        });

        if ($removed !== []) {
            $this->brand->forget();
        }

        return $removed;
    }
}
