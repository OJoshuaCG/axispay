<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Models\PlatformSetting;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Chooses what the platform brand shows: logo and name, logo only, or name
 * only (ADR-0053). A superadmin setting, not a deploy variable; audited with
 * the before and after values. Choosing the current mode is a no-op.
 */
final readonly class ChangeBrandDisplayMode
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private PlatformBrand $brand,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     */
    public function handle(PlatformAdmin $actor, BrandDisplayMode $mode): BrandDisplayMode
    {
        Gate::forUser($actor)->authorize('manage', PlatformLogo::class);
        $this->reauthentication->ensureConfirmed();

        $changed = DB::transaction(function () use ($actor, $mode): bool {
            $setting = PlatformSetting::query()->whereKey(PlatformSetting::BRAND_DISPLAY_MODE)->lockForUpdate()->first();
            $before = $setting !== null && is_string($setting->value)
                ? BrandDisplayMode::tryFrom($setting->value) ?? BrandDisplayMode::LogoAndName
                : BrandDisplayMode::LogoAndName;

            if ($before === $mode) {
                return false;
            }

            $setting ??= (new PlatformSetting)->forceFill(['key' => PlatformSetting::BRAND_DISPLAY_MODE]);
            $setting->forceFill(['value' => $mode->value])->save();

            $this->audit->record(AuditAction::PlatformBrandDisplayModeChanged, null, [
                'before' => $before->value,
                'after' => $mode->value,
            ], platform: true, actor: Actor::platformAdmin($actor->id));

            return true;
        });

        if ($changed) {
            $this->brand->forget();
        }

        return $mode;
    }
}
