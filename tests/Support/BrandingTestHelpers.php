<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\PlatformFavicon;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Models\PlatformSetting;
use App\Modules\Branding\Services\PlatformBrand;
use GdImage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Images and stored logos for the branding tests (ADR-0053). Every image is
 * drawn with GD in memory, so no binary fixture lives in the repository.
 */
final class BrandingTestHelpers
{
    /** A text that must never survive re-encoding. */
    public const string EXIF_MARKER = 'AXISPAY-SECRET-GPS-19.4326N-99.1332W';

    public static function png(int $width = 40, int $height = 12, bool $transparent = false): string
    {
        $image = self::canvas($width, $height, $transparent);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function jpeg(int $width = 40, int $height = 12): string
    {
        $image = self::canvas($width, $height, false);
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    public static function webp(int $width = 40, int $height = 12): string
    {
        $image = self::canvas($width, $height, true);
        ob_start();
        imagewebp($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG carrying an APP1 "Exif" segment (right after the SOI marker)
     * with a recognizable marker text, like a phone photo with GPS data.
     */
    public static function jpegWithExif(int $width = 40, int $height = 12): string
    {
        $jpeg = self::jpeg($width, $height);
        $payload = "Exif\0\0".'II*'."\0\x08\0\0\0".self::EXIF_MARKER;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
    }

    public static function gif(): string
    {
        $image = self::canvas(4, 4, false);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /** Stores a logo directly (bypassing the action), then drops the cached brand. */
    public static function storeLogo(LogoVariant $variant = LogoVariant::Light, ?string $bytes = null): PlatformLogo
    {
        $bytes ??= self::png();
        $logo = new PlatformLogo;
        $logo->forceFill([
            'variant' => $variant,
            'version' => strtolower((string) Str::ulid()),
            'mime_type' => 'image/png',
            'width' => 40,
            'height' => 12,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'content' => $bytes,
        ])->save();

        app(PlatformBrand::class)->forget();

        return $logo;
    }

    /** An ICO file header (a real favicon.ico starts with these bytes). */
    public static function ico(): string
    {
        return "\0\0\1\0\1\0\x10\x10\0\0\1\0\x20\0".str_repeat("\0", 64);
    }

    /**
     * Stores a favicon directly (bypassing the action): one row per size,
     * then drops the cached brand.
     *
     * @return array<int, PlatformFavicon> size => row
     */
    public static function storeFavicon(): array
    {
        $rows = [];

        foreach (FaviconSize::cases() as $size) {
            $bytes = self::png($size->value, $size->value);
            $favicon = new PlatformFavicon;
            $favicon->forceFill([
                'size' => $size,
                'version' => strtolower((string) Str::ulid()),
                'mime_type' => 'image/png',
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'content' => $bytes,
            ])->save();
            $rows[$size->value] = $favicon;
        }

        app(PlatformBrand::class)->forget();

        return $rows;
    }

    /** The served URL of a stored favicon size. */
    public static function faviconUrl(PlatformFavicon $favicon): string
    {
        return PlatformBrand::FAVICON_PATH.'/'.$favicon->size->value.'/'.$favicon->version.'.png';
    }

    /** Sets the display mode directly (bypassing the action), then drops the cached brand. */
    public static function setMode(BrandDisplayMode $mode): void
    {
        $setting = PlatformSetting::query()->find(PlatformSetting::BRAND_DISPLAY_MODE)
            ?? (new PlatformSetting)->forceFill(['key' => PlatformSetting::BRAND_DISPLAY_MODE]);
        $setting->forceFill(['value' => $mode->value])->save();

        app(PlatformBrand::class)->forget();
    }

    /** The served URL of a stored logo. */
    public static function url(PlatformLogo $logo): string
    {
        return PlatformBrand::LOGO_PATH.'/'.$logo->variant->value.'/'.$logo->version.'.png';
    }

    private static function canvas(int $width, int $height, bool $transparent): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        if (! $image instanceof GdImage) {
            throw new LogicException('GD could not create the test image.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $fill = $transparent
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocate($image, 31, 99, 199);
        imagefill($image, 0, 0, (int) $fill);

        // One opaque pixel, so a transparent image is not entirely empty.
        imagesetpixel($image, 0, 0, (int) imagecolorallocatealpha($image, 200, 20, 20, 0));

        return $image;
    }
}
