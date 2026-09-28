<?php

declare(strict_types=1);

use App\Modules\Branding\Enums\ImageRejection;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Services\ImageNormalizer;
use Tests\Support\BrandingTestHelpers as Images;

/*
 * The upload rules of plan section 18 (ADR-0053): the real type by its first
 * bytes, PNG/JPEG/WebP only, size and dimension limits, re-encoded to PNG
 * without metadata, transparency kept, scaled down to 400 × 120.
 */

function normalizerRejection(string $bytes): ImageRejection
{
    $e = thrownBy(InvalidImageException::class, static fn () => (new ImageNormalizer)->normalize($bytes));

    return $e instanceof InvalidImageException ? $e->rejection : throw new LogicException('Expected an InvalidImageException.');
}

it('accepts PNG, JPEG and WebP and always returns a PNG', function (string $bytes): void {
    $image = (new ImageNormalizer)->normalize($bytes);

    expect($image->mimeType)->toBe('image/png')
        ->and(str_starts_with($image->bytes, "\x89PNG\r\n\x1A\n"))->toBeTrue()
        ->and($image->width)->toBe(40)
        ->and($image->height)->toBe(12)
        ->and($image->sha256)->toBe(hash('sha256', $image->bytes));
})->with([
    'png' => fn (): string => Images::png(),
    'jpeg' => fn (): string => Images::jpeg(),
    'webp' => fn (): string => Images::webp(),
]);

it('refuses SVG, GIF and other content whatever the file is called', function (string $bytes): void {
    expect(normalizerRejection($bytes))->toBe(ImageRejection::UnsupportedType);
})->with([
    'svg' => fn (): string => Images::svg(),
    'svg with an XML prolog' => fn (): string => '<?xml version="1.0"?>'.Images::svg(),
    'gif' => fn (): string => Images::gif(),
    'html' => fn (): string => '<html><body><script>alert(1)</script></body></html>',
    'php' => fn (): string => '<?php echo 1;',
]);

it('refuses a file whose first bytes claim one type but whose content is another or broken', function (string $bytes): void {
    expect(normalizerRejection($bytes))->toBe(ImageRejection::Unreadable);
})->with([
    'png signature, svg body' => fn (): string => "\x89PNG\r\n\x1A\n".Images::svg(),
    'jpeg signature, png body' => fn (): string => "\xFF\xD8\xFF".substr(Images::png(), 3),
    'truncated png' => fn (): string => substr(Images::png(), 0, 30),
]);

it('refuses an empty upload', function (): void {
    expect(normalizerRejection(''))->toBe(ImageRejection::Empty);
});

it('refuses files larger than 1 MB before reading them', function (): void {
    $bytes = Images::png().str_repeat("\0", ImageNormalizer::MAX_BYTES);

    expect(normalizerRejection($bytes))->toBe(ImageRejection::TooLarge);
});

it('refuses images larger than 2000 × 2000 pixels', function (int $width, int $height): void {
    expect(normalizerRejection(Images::png($width, $height)))->toBe(ImageRejection::DimensionsTooLarge);
})->with([
    'too wide' => [2001, 10],
    'too tall' => [10, 2001],
]);

it('accepts exactly 2000 pixels and scales it down to the logo box', function (): void {
    $image = (new ImageNormalizer)->normalize(Images::png(2000, 600));

    expect($image->width)->toBe(400)->and($image->height)->toBe(120);
});

it('scales down to fit 400 × 120 keeping the proportions, and never scales up', function (int $width, int $height, int $expectedWidth, int $expectedHeight): void {
    $image = (new ImageNormalizer)->normalize(Images::png($width, $height));

    expect([$image->width, $image->height])->toBe([$expectedWidth, $expectedHeight]);
})->with([
    'wide' => [800, 120, 400, 60],
    'tall' => [100, 600, 20, 120],
    'small' => [100, 30, 100, 30],
]);

it('strips EXIF and every other metadata block when re-encoding', function (): void {
    $source = Images::jpegWithExif();
    expect(str_contains($source, Images::EXIF_MARKER))->toBeTrue();

    $image = (new ImageNormalizer)->normalize($source);

    expect(str_contains($image->bytes, Images::EXIF_MARKER))->toBeFalse()
        ->and(str_contains($image->bytes, 'Exif'))->toBeFalse()
        ->and(str_contains($image->bytes, 'eXIf'))->toBeFalse();
});

it('keeps transparency', function (): void {
    $image = (new ImageNormalizer)->normalize(Images::png(40, 12, transparent: true));
    $decoded = imagecreatefromstring($image->bytes);
    assert($decoded instanceof GdImage);

    $transparent = imagecolorsforindex($decoded, (int) imagecolorat($decoded, 20, 6));
    $opaque = imagecolorsforindex($decoded, (int) imagecolorat($decoded, 0, 0));

    expect($transparent['alpha'])->toBe(127)->and($opaque['alpha'])->toBe(0);
});

it('explains each refusal in English and Spanish', function (string $locale): void {
    app()->setLocale($locale);

    foreach (ImageRejection::cases() as $rejection) {
        expect($rejection->message())->not->toStartWith('branding.');
    }
})->with(['en', 'es']);
