<?php

declare(strict_types=1);

use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Enums\LegalDocumentRejection;
use App\Modules\Legal\Exceptions\InvalidLegalDocumentException;

/*
 * ADR-0056: a legal document is a non-empty text of at most 50,000
 * characters or an absolute http(s) link without credentials; only the
 * field of the chosen format is kept.
 */

function legalRejection(LegalDocumentFormat $format, mixed $body, mixed $url): LegalDocumentRejection
{
    $e = thrownBy(InvalidLegalDocumentException::class, static fn () => LegalDocumentData::from(LegalDocumentKind::Privacy, $format, $body, $url));

    return $e instanceof InvalidLegalDocumentException ? $e->rejection : throw new LogicException('Expected an InvalidLegalDocumentException.');
}

it('keeps a text trimmed with normalized line endings, and drops the url', function (): void {
    $data = LegalDocumentData::from(LegalDocumentKind::Terms, LegalDocumentFormat::Text, "  # Título\r\n\r\nTexto.\r  ", 'https://ignored.example');

    expect($data->kind)->toBe(LegalDocumentKind::Terms)
        ->and($data->body)->toBe("# Título\n\nTexto.")
        ->and($data->url)->toBeNull();
});

it('keeps a link and drops the body', function (): void {
    $data = LegalDocumentData::from(LegalDocumentKind::Privacy, LegalDocumentFormat::Url, 'ignored', ' https://tienda.example/aviso?v=2 ');

    expect($data->url)->toBe('https://tienda.example/aviso?v=2')
        ->and($data->body)->toBeNull();
});

it('accepts a text of exactly the maximum length, counted in characters', function (): void {
    $body = str_repeat('ñ', LegalDocumentData::MAX_BODY_LENGTH);

    expect(LegalDocumentData::from(LegalDocumentKind::Privacy, LegalDocumentFormat::Text, $body, null)->body)->toBe($body);
});

it('refuses an empty or too long text', function (mixed $body, LegalDocumentRejection $rejection): void {
    expect(legalRejection(LegalDocumentFormat::Text, $body, null))->toBe($rejection)
        ->and($rejection->field())->toBe('body');
})->with([
    'null' => [null, LegalDocumentRejection::EmptyBody],
    'blank' => ["  \r\n ", LegalDocumentRejection::EmptyBody],
    'too long' => [str_repeat('a', LegalDocumentData::MAX_BODY_LENGTH + 1), LegalDocumentRejection::BodyTooLong],
]);

it('refuses a link that is not an absolute http(s) address without credentials', function (mixed $url): void {
    expect(legalRejection(LegalDocumentFormat::Url, null, $url))->toBe(LegalDocumentRejection::InvalidUrl)
        ->and(LegalDocumentRejection::InvalidUrl->field())->toBe('url');
})->with([
    'null' => [null],
    'empty' => [''],
    'relative' => ['/aviso'],
    'no scheme' => ['tienda.example/aviso'],
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html;base64,PHNjcmlwdD4='],
    'ftp' => ['ftp://tienda.example/aviso'],
    'credentials' => ['https://user:secret@tienda.example/aviso'],
    'space' => ['https://tienda.example/aviso de privacidad'],
    'too long' => ['https://tienda.example/'.str_repeat('a', LegalDocumentData::MAX_URL_LENGTH)],
]);

it('accepts http links for environments without TLS', function (): void {
    expect(LegalDocumentData::from(LegalDocumentKind::Privacy, LegalDocumentFormat::Url, null, 'http://tienda.test/aviso')->url)->toBe('http://tienda.test/aviso');
});
