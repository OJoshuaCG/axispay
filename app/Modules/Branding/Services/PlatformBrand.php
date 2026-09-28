<?php

declare(strict_types=1);

namespace App\Modules\Branding\Services;

use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Models\PlatformSetting;
use App\Modules\Shared\Support\Brand;
use Illuminate\Contracts\Cache\Repository;

/**
 * How the platform brand is shown now (ADR-0053): the logo URLs (light,
 * and dark falling back to light), the display mode, and the name
 * (Brand::displayName(), ADR-0037). Without a logo the name is always shown,
 * whatever the mode.
 *
 * Read from the cache (never the logo bytes), and remembered for the rest of
 * the request, so rendering a page does not query the database; every
 * change calls forget().
 */
final class PlatformBrand
{
    private const string CACHE_KEY = 'branding:platform:v1';

    /** Relative path: served same-origin on every host that shows it. */
    public const string LOGO_PATH = '/branding/platform-logo';

    /** @var array{mode: string, logos: array<string, string>}|null */
    private ?array $state = null;

    public function __construct(private readonly Repository $cache) {}

    public function mode(): BrandDisplayMode
    {
        return $this->hasLogo()
            ? BrandDisplayMode::tryFrom($this->state()['mode']) ?? BrandDisplayMode::LogoAndName
            : BrandDisplayMode::NameOnly;
    }

    /** The mode the superadmin chose (the effective one may fall back to the name). */
    public function configuredMode(): BrandDisplayMode
    {
        return BrandDisplayMode::tryFrom($this->state()['mode']) ?? BrandDisplayMode::LogoAndName;
    }

    public function hasLogo(): bool
    {
        return isset($this->state()['logos'][LogoVariant::Light->value]);
    }

    public function hasVariant(LogoVariant $variant): bool
    {
        return isset($this->state()['logos'][$variant->value]);
    }

    public function showsLogo(): bool
    {
        return $this->mode() !== BrandDisplayMode::NameOnly;
    }

    public function showsName(): bool
    {
        return $this->mode() !== BrandDisplayMode::LogoOnly;
    }

    public function name(): string
    {
        return Brand::displayName();
    }

    /**
     * The logo's URL (relative, versioned) for a variant; dark falls back to
     * light. Null without a logo.
     */
    public function logoUrl(LogoVariant $variant = LogoVariant::Light): ?string
    {
        $logos = $this->state()['logos'];
        $chosen = isset($logos[$variant->value]) ? $variant : LogoVariant::Light;
        $version = $logos[$chosen->value] ?? null;

        return $version !== null ? self::LOGO_PATH.'/'.$chosen->value.'/'.$version.'.png' : null;
    }

    /** Drops what is remembered (a logo or the mode changed). */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->state = null;
    }

    /**
     * @return array{mode: string, logos: array<string, string>}
     */
    private function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        /** @var mixed $cached */
        $cached = $this->cache->rememberForever(self::CACHE_KEY, static function (): array {
            $logos = [];

            foreach (PlatformLogo::query()->select(['variant', 'version'])->get() as $logo) {
                $logos[$logo->variant->value] = $logo->version;
            }

            $mode = PlatformSetting::query()->whereKey(PlatformSetting::BRAND_DISPLAY_MODE)->value('value');

            return ['mode' => is_string($mode) ? $mode : BrandDisplayMode::LogoAndName->value, 'logos' => $logos];
        });

        return $this->state = self::shape($cached);
    }

    /**
     * @return array{mode: string, logos: array<string, string>}
     */
    private static function shape(mixed $cached): array
    {
        $mode = is_array($cached) && is_string($cached['mode'] ?? null) ? $cached['mode'] : BrandDisplayMode::LogoAndName->value;
        $logos = [];

        foreach (is_array($cached) && is_array($cached['logos'] ?? null) ? $cached['logos'] : [] as $variant => $version) {
            if (is_string($variant) && is_string($version)) {
                $logos[$variant] = $version;
            }
        }

        return ['mode' => $mode, 'logos' => $logos];
    }
}
