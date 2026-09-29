<?php

declare(strict_types=1);

use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Branding\Enums\ImageRejection;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Services\ImageNormalizer;
use Tests\Support\BrandingTestHelpers as Images;

/*
 * Favicon uploads (ADR-0053): the logo's rules (real type, 1 MB, 2000 px)
 * plus at least 32 × 32; a non-square image is centered on a transparent
 * square; one PNG per size (32, 180, 192) without metadata.
 */

function faviconRejection(string $bytes): ImageRejection
{
    $e = thrownBy(InvalidImageException::class, static fn () => (new ImageNormalizer)->squareIcons($bytes, FaviconSize::pixels()));

    return $e->rejection;
}

it('generates one square PNG per favicon size', function (string $bytes): void {
    $icons = (new ImageNormalizer)->squareIcons($bytes, FaviconSize::pixels());

    expect(array_keys($icons))->toBe([32, 180, 192]);

    foreach ($icons as $size => $icon) {
        $info = getimagesizefromstring($icon->bytes);

        expect($icon->mimeType)->toBe('image/png')
            ->and(str_starts_with($icon->bytes, "\x89PNG\r\n\x1A\n"))->toBeTrue()
            ->and([$icon->width, $icon->height])->toBe([$size, $size])
            ->and($info !== false ? [$info[0], $info[1]] : null)->toBe([$size, $size])
            ->and($icon->sha256)->toBe(hash('sha256', $icon->bytes));
    }
})->with([
    'png' => fn (): string => Images::png(64, 64),
    'jpeg' => fn (): string => Images::jpeg(64, 64),
    'webp' => fn (): string => Images::webp(64, 64),
]);

it('centers a non-square image on a transparent square', function (): void {
    // 64 × 32, opaque: at 192 px it becomes 192 × 96 in the middle.
    $source = imagecreatetruecolor(64, 32);
    assert($source instanceof GdImage);
    imagefill($source, 0, 0, (int) imagecolorallocate($source, 31, 99, 199));
    ob_start();
    imagepng($source);
    $bytes = (string) ob_get_clean();

    $icon = (new ImageNormalizer)->squareIcons($bytes, [192])[192];
    $decoded = imagecreatefromstring($icon->bytes);
    assert($decoded instanceof GdImage);

    $top = imagecolorsforindex($decoded, (int) imagecolorat($decoded, 96, 10));
    $bottom = imagecolorsforindex($decoded, (int) imagecolorat($decoded, 96, 185));
    $middle = imagecolorsforindex($decoded, (int) imagecolorat($decoded, 96, 96));

    expect($top['alpha'])->toBe(127)
        ->and($bottom['alpha'])->toBe(127)
        ->and($middle['alpha'])->toBe(0);
});

it('refuses SVG, ICO and other content whatever the file is called', function (string $bytes): void {
    expect(faviconRejection($bytes))->toBe(ImageRejection::UnsupportedType);
})->with([
    'svg' => fn (): string => Images::svg(),
    'ico' => fn (): string => Images::ico(),
    'gif' => fn (): string => Images::gif(),
]);

it('refuses images smaller than 32 × 32 on either side', function (int $width, int $height): void {
    expect(faviconRejection(Images::png($width, $height)))->toBe(ImageRejection::DimensionsTooSmall);
})->with([
    'too narrow' => [31, 64],
    'too short' => [64, 31],
    'tiny' => [16, 16],
]);

it('accepts exactly 32 × 32', function (): void {
    expect((new ImageNormalizer)->squareIcons(Images::png(32, 32), [32])[32]->width)->toBe(32);
});

it('refuses files larger than 1 MB and images larger than 2000 × 2000', function (): void {
    expect(faviconRejection(Images::png(64, 64).str_repeat("\0", ImageNormalizer::MAX_BYTES)))->toBe(ImageRejection::TooLarge)
        ->and(faviconRejection(Images::png(2001, 64)))->toBe(ImageRejection::DimensionsTooLarge);
});

it('strips EXIF from the generated icons', function (): void {
    $icons = (new ImageNormalizer)->squareIcons(Images::jpegWithExif(64, 64), FaviconSize::pixels());

    foreach ($icons as $icon) {
        expect(str_contains($icon->bytes, Images::EXIF_MARKER))->toBeFalse();
    }
});

it('still lets logos be smaller than 32 px (the minimum is for icons only)', function (): void {
    expect((new ImageNormalizer)->normalize(Images::png(20, 10))->width)->toBe(20);
});
