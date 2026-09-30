<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Data\NormalizedImage;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Models\TenantLogo;
use App\Modules\Branding\Services\ImageNormalizer;
use App\Modules\Branding\Services\TenantLogos;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Sets (or replaces) one variant of the merchant's logo (ADR-0056 part B).
 *
 *  - `settings:manage` and a writable panel (policy; impersonation is denied
 *    there, plan 17.4); no re-authentication (plan 17.3 does not list it);
 *  - the upload is checked and re-encoded by ImageNormalizer (real type by
 *    its first bytes, 1 MB and 2000 × 2000 limits, no metadata kept), scaled
 *    down to fit 800 × 240;
 *  - every change gets a new random version, so the served URL changes and
 *    the long browser cache never shows an old logo;
 *  - audited in the tenant's log with the image's metadata only.
 *
 * Takes effect on the payment pages at once; the panels keep the platform
 * logo.
 */
final readonly class UpdateTenantLogo
{
    public function __construct(
        private ImageNormalizer $normalizer,
        private AuditLogger $audit,
        private TenantLogos $logos,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidImageException
     */
    public function handle(User $actor, LogoVariant $variant, #[SensitiveParameter] string $bytes): TenantLogo
    {
        Gate::forUser($actor)->authorize('manage', TenantLogo::class);

        $image = $this->normalizer->normalize($bytes, ImageNormalizer::LOGO_MAX_WIDTH, ImageNormalizer::LOGO_MAX_HEIGHT);

        try {
            $logo = $this->save($actor, $variant, $image);
        } catch (UniqueConstraintViolationException) {
            // Two first uploads of the same variant at once: the other one won; replace it.
            $logo = $this->save($actor, $variant, $image);
        }

        $this->logos->forget($actor->tenant_id);

        return $logo;
    }

    private function save(User $actor, LogoVariant $variant, NormalizedImage $image): TenantLogo
    {
        return DB::transaction(function () use ($actor, $variant, $image): TenantLogo {
            $logo = TenantLogo::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('variant', $variant->value)
                ->lockForUpdate()
                ->first() ?? (new TenantLogo)->forceFill(['tenant_id' => $actor->tenant_id, 'variant' => $variant]);
            $previous = $logo->exists ? $logo->sha256 : null;

            $logo->forceFill([
                'version' => strtolower((string) Str::ulid()),
                'mime_type' => $image->mimeType,
                'width' => $image->width,
                'height' => $image->height,
                'size_bytes' => strlen($image->bytes),
                'sha256' => $image->sha256,
                'content' => $image->bytes,
                'uploaded_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::TenantLogoUpdated, $logo, [
                'variant' => $variant->value,
                'width' => $image->width,
                'height' => $image->height,
                'size_bytes' => strlen($image->bytes),
                'sha256_hash' => $image->sha256,
                'previous_sha256_hash' => $previous,
            ], tenantId: $actor->tenant_id, actor: Actor::user($actor->id));

            return $logo;
        });
    }
}
