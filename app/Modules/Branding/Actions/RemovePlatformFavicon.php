<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Models\PlatformFavicon;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes the platform favicon (every size) and goes back to the default
 * favicon (ADR-0053). Same guards and audit as UpdatePlatformFavicon.
 * Removing a favicon that is not set is a no-op (no audit row).
 */
final readonly class RemovePlatformFavicon
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private PlatformBrand $brand,
    ) {}

    /**
     * @return bool whether a favicon was removed
     *
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     */
    public function handle(PlatformAdmin $actor): bool
    {
        Gate::forUser($actor)->authorize('manage', PlatformFavicon::class);
        $this->reauthentication->ensureConfirmed();

        $removed = DB::transaction(function () use ($actor): bool {
            $favicons = PlatformFavicon::query()->lockForUpdate()->get();

            if ($favicons->isEmpty()) {
                return false;
            }

            $changes = ['sizes' => []];

            foreach ($favicons as $favicon) {
                $favicon->delete();
                $changes['sizes'][(string) $favicon->size->value] = ['sha256_hash' => $favicon->sha256];
            }

            $this->audit->record(AuditAction::PlatformFaviconRemoved, $favicons->first(), $changes, platform: true, actor: Actor::platformAdmin($actor->id));

            return true;
        });

        if ($removed) {
            $this->brand->forget();
        }

        return $removed;
    }
}
