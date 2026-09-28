<?php

declare(strict_types=1);

namespace App\Modules\Branding\Services;

use App\Modules\Branding\Data\NormalizedImage;
use App\Modules\Branding\Enums\ImageRejection;
use App\Modules\Branding\Exceptions\InvalidImageException;
use GdImage;

/**
 * The upload rules of plan section 18 for every brand image (the platform
 * logo now, tenant logos in Phase 8; ADR-0038, ADR-0053):
 *
 *  - PNG, JPEG or WebP only, recognized by their magic bytes (never by the
 *    file name or the declared type); SVG and anything else are refused;
 *  - at most 1 MB and 2000 × 2000 pixels;
 *  - decoded and re-encoded with GD to a PNG (transparency kept), scaled
 *    down to fit the requested box (never up). Re-encoding drops every
 *    metadata block (EXIF, XMP, comments) and anything hidden in the file.
 *
 * Storage and serving are the caller's concern.
 */
final class ImageNormalizer
{
    public const int MAX_BYTES = 1_048_576;

    public const int MAX_SOURCE_PIXELS = 2000;

    /** Plan 18: logos are normalized to at most 400 × 120. */
    public const int LOGO_MAX_WIDTH = 400;

    public const int LOGO_MAX_HEIGHT = 120;

    /**
     * @throws InvalidImageException
     */
    public function normalize(string $bytes, int $maxWidth = self::LOGO_MAX_WIDTH, int $maxHeight = self::LOGO_MAX_HEIGHT): NormalizedImage
    {
        if ($bytes === '') {
            throw new InvalidImageException(ImageRejection::Empty);
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidImageException(ImageRejection::TooLarge);
        }

        $type = self::sniff($bytes) ?? throw new InvalidImageException(ImageRejection::UnsupportedType);
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== $type) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        [$width, $height] = [$info[0], $info[1]];

        if ($width < 1 || $height < 1) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        if ($width > self::MAX_SOURCE_PIXELS || $height > self::MAX_SOURCE_PIXELS) {
            throw new InvalidImageException(ImageRejection::DimensionsTooLarge);
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new InvalidImageException(ImageRejection::Unreadable);
        }

        $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

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

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 9);
        $encoded = (string) ob_get_clean();

        return new NormalizedImage($encoded, 'image/png', $targetWidth, $targetHeight, hash('sha256', $encoded));
    }

    /** The real type from the first bytes, or null (SVG, GIF, anything else). */
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
