<?php

declare(strict_types=1);

namespace App\Modules\Branding\Services;

use App\Modules\Branding\Data\NormalizedImage;
use App\Modules\Branding\Enums\ImageRejection;
use App\Modules\Branding\Exceptions\InvalidImageException;
use GdImage;

/**
 * The upload rules of plan section 18 for every brand image (the platform
 * logo and favicon, and the merchant's logo; ADR-0038, ADR-0053, ADR-0056):
 *
 *  - PNG, JPEG or WebP only, recognized by their magic bytes (never by the
 *    file name or the declared type); SVG, ICO and anything else are refused;
 *  - at most 1 MB and 2000 × 2000 pixels (icons: at least 32 × 32);
 *  - decoded and re-encoded with GD to a PNG (transparency kept). Re-encoding
 *    drops every metadata block (EXIF, XMP, comments) and anything hidden in
 *    the file.
 *
 * Storage and serving are the caller's concern.
 */
final class ImageNormalizer
{
    public const int MAX_BYTES = 1_048_576;

    public const int MAX_SOURCE_PIXELS = 2000;

    /**
     * The merchant's logo (ADR-0056, amending plan 18's 400 × 120): shown
     * large at the top of the payment pages, so it is kept at up to
     * 800 × 240 and stays sharp there, retina included.
     */
    public const int LOGO_MAX_WIDTH = 800;

    public const int LOGO_MAX_HEIGHT = 240;

    /**
     * The platform logo is kept larger (ADR-0053 amendment of 2026-09-29), so
     * it stays sharp across the full-height sidebar and the sign-in pages
     * (ADR-0054), retina included.
     */
    public const int PLATFORM_LOGO_MAX_WIDTH = 1024;

    public const int PLATFORM_LOGO_MAX_HEIGHT = 512;

    /** Smallest favicon source: the browser tab size. */
    public const int ICON_MIN_SOURCE_PIXELS = 32;

    /**
     * A logo: scaled down to fit the box (never up). The default box is the
     * merchant's (ADR-0056); the platform logo passes PLATFORM_LOGO_MAX_*.
     *
     * @throws InvalidImageException
     */
    public function normalize(string $bytes, int $maxWidth = self::LOGO_MAX_WIDTH, int $maxHeight = self::LOGO_MAX_HEIGHT): NormalizedImage
    {
        [$source, $width, $height] = $this->decode($bytes, 1);

        $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = self::transparentCanvas($targetWidth, $targetHeight);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return self::encode($target, $targetWidth, $targetHeight);
    }

    /**
     * Square icons (favicon): the image is centered on a transparent square
     * canvas (its longer side), then resized to each size, up or down.
     *
     * @param  list<int>  $sizes
     * @return array<int, NormalizedImage> size => image
     *
     * @throws InvalidImageException
     */
    public function squareIcons(string $bytes, array $sizes, int $minSource = self::ICON_MIN_SOURCE_PIXELS): array
    {
        [$source, $width, $height] = $this->decode($bytes, $minSource);
        $side = max($width, $height);
        $icons = [];

        foreach ($sizes as $size) {
            $size = max(1, $size);
            $scale = $size / $side;
            $drawWidth = max(1, (int) round($width * $scale));
            $drawHeight = max(1, (int) round($height * $scale));

            $target = self::transparentCanvas($size, $size);
            imagecopyresampled(
                $target,
                $source,
                intdiv($size - $drawWidth, 2),
                intdiv($size - $drawHeight, 2),
                0,
                0,
                $drawWidth,
                $drawHeight,
                $width,
                $height,
            );

            $icons[$size] = self::encode($target, $size, $size);
        }

        return $icons;
    }

    /**
     * Every check before and while decoding.
     *
     * @return array{0: GdImage, 1: int<1, max>, 2: int<1, max>}
     *
     * @throws InvalidImageException
     */
    private function decode(string $bytes, int $minPixels): array
    {
        if ($bytes === '') {
            throw new InvalidImageException(ImageRejection::Empty);
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidImageException(ImageRejection::TooLarge);
        }

        $type = self::sniff($bytes) ?? throw new InvalidImageException(ImageRejection::UnsupportedType);

        // getimagesize() reads a PNG's size at fixed offsets without checking
        // that the IHDR chunk is there, so a broken file would pass with any
        // made-up size (and be refused for the wrong reason, or not at all).
        if ($type === IMAGETYPE_PNG && substr($bytes, 8, 8) !== "\x00\x00\x00\x0DIHDR") {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== $type) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        [$width, $height] = [$info[0], $info[1]];

        if ($width < 1 || $height < 1) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        // Checked before decoding: a huge image is never expanded in memory.
        if ($width > self::MAX_SOURCE_PIXELS || $height > self::MAX_SOURCE_PIXELS) {
            throw new InvalidImageException(ImageRejection::DimensionsTooLarge);
        }

        if ($width < $minPixels || $height < $minPixels) {
            throw new InvalidImageException(ImageRejection::DimensionsTooSmall);
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        return [$source, $width, $height];
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     *
     * @throws InvalidImageException
     */
    private static function transparentCanvas(int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        if (! $target instanceof GdImage) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        // Keep transparency: start from a fully transparent canvas.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefill($target, 0, 0, $transparent);
        }

        return $target;
    }

    private static function encode(GdImage $image, int $width, int $height): NormalizedImage
    {
        ob_start();
        imagepng($image, null, 9);
        $encoded = (string) ob_get_clean();

        return new NormalizedImage($encoded, 'image/png', $width, $height, hash('sha256', $encoded));
    }

    /** The real type from the first bytes, or null (SVG, ICO, GIF, anything else). */
    private static function sniff(string $bytes): ?int
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => IMAGETYPE_PNG,
            str_starts_with($bytes, "\xFF\xD8\xFF") => IMAGETYPE_JPEG,
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => IMAGETYPE_WEBP,
            default => null,
        };
    }
}
