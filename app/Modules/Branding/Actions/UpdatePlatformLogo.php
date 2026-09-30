<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Services\ImageNormalizer;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Sets (or replaces) one variant of the platform logo (ADR-0053).
 *
 *  - `platform:branding:manage` and a fresh re-authentication;
 *  - the upload is checked and re-encoded by ImageNormalizer (real type by
 *    its first bytes, size and dimension limits, no metadata kept), scaled
 *    down to fit 1024 × 512 (the platform box; merchant logos: 800 × 240, ADR-0056);
 *  - every change gets a new random version, so the served URL changes and
 *    the long browser cache never shows an old logo;
 *  - audited in the platform log; the cached brand is dropped after commit.
 */
final readonly class UpdatePlatformLogo
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private ImageNormalizer $normalizer,
        private AuditLogger $audit,
        private PlatformBrand $brand,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     * @throws InvalidImageException
     */
    public function handle(PlatformAdmin $actor, LogoVariant $variant, #[SensitiveParameter] string $bytes): PlatformLogo
    {
        Gate::forUser($actor)->authorize('manage', PlatformLogo::class);
        $this->reauthentication->ensureConfirmed();

        $image = $this->normalizer->normalize($bytes, ImageNormalizer::PLATFORM_LOGO_MAX_WIDTH, ImageNormalizer::PLATFORM_LOGO_MAX_HEIGHT);

        $logo = DB::transaction(function () use ($actor, $variant, $image): PlatformLogo {
            $logo = PlatformLogo::query()->where('variant', $variant->value)->lockForUpdate()->first() ?? new PlatformLogo;
            $previous = $logo->exists ? $logo->sha256 : null;

            $logo->forceFill([
                'variant' => $variant,
                'version' => strtolower((string) Str::ulid()),
                'mime_type' => $image->mimeType,
                'width' => $image->width,
                'height' => $image->height,
                'size_bytes' => strlen($image->bytes),
                'sha256' => $image->sha256,
                'content' => $image->bytes,
                'uploaded_by_platform_admin_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::PlatformLogoUpdated, $logo, [
                'variant' => $variant->value,
                'width' => $image->width,
                'height' => $image->height,
                'size_bytes' => strlen($image->bytes),
                'sha256_hash' => $image->sha256,
                'previous_sha256_hash' => $previous,
            ], platform: true, actor: Actor::platformAdmin($actor->id));

            return $logo;
        });

        $this->brand->forget();

        return $logo;
    }
}
