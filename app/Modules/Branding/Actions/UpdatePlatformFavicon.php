<?php

declare(strict_types=1);

namespace App\Modules\Branding\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Models\PlatformFavicon;
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
 * Sets (or replaces) the platform favicon (ADR-0053).
 *
 *  - `platform:branding:manage` and a fresh re-authentication;
 *  - the upload is checked by ImageNormalizer (real type, 1 MB, at least
 *    32 × 32 and at most 2000 × 2000), centered on a transparent square and
 *    re-encoded to one PNG per size (32, 180, 192), without metadata;
 *  - each size gets a new random version, so its URL changes and the long
 *    browser cache never shows an old icon;
 *  - audited in the platform log; the cached brand is dropped after commit.
 */
final readonly class UpdatePlatformFavicon
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private ImageNormalizer $normalizer,
        private AuditLogger $audit,
        private PlatformBrand $brand,
    ) {}

    /**
     * @return list<PlatformFavicon> one row per size
     *
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     * @throws InvalidImageException
     */
    public function handle(PlatformAdmin $actor, #[SensitiveParameter] string $bytes): array
    {
        Gate::forUser($actor)->authorize('manage', PlatformFavicon::class);
        $this->reauthentication->ensureConfirmed();

        $icons = $this->normalizer->squareIcons($bytes, FaviconSize::pixels());

        $favicons = DB::transaction(function () use ($actor, $icons): array {
            $existing = PlatformFavicon::query()->lockForUpdate()->get()->keyBy(static fn (PlatformFavicon $favicon): int => $favicon->size->value);
            $favicons = [];
            $changes = ['sizes' => []];

            foreach (FaviconSize::cases() as $size) {
                $icon = $icons[$size->value];
                $favicon = $existing->get($size->value) ?? new PlatformFavicon;
                $previous = $favicon->exists ? $favicon->sha256 : null;

                $favicon->forceFill([
                    'size' => $size,
                    'version' => strtolower((string) Str::ulid()),
                    'mime_type' => $icon->mimeType,
                    'size_bytes' => strlen($icon->bytes),
                    'sha256' => $icon->sha256,
                    'content' => $icon->bytes,
                    'uploaded_by_platform_admin_id' => $actor->id,
                ])->save();

                $favicons[] = $favicon;
                $changes['sizes'][(string) $size->value] = [
                    'size_bytes' => strlen($icon->bytes),
                    'sha256_hash' => $icon->sha256,
                    'previous_sha256_hash' => $previous,
                ];
            }

            $this->audit->record(AuditAction::PlatformFaviconUpdated, $favicons[0], $changes, platform: true, actor: Actor::platformAdmin($actor->id));

            return $favicons;
        });

        $this->brand->forget();

        return $favicons;
    }
}
