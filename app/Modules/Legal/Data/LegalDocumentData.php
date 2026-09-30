<?php

declare(strict_types=1);

namespace App\Modules\Legal\Data;

use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Enums\LegalDocumentRejection;
use App\Modules\Legal\Exceptions\InvalidLegalDocumentException;

/**
 * A legal document to save (ADR-0056), already checked: a text has a
 * non-empty body of at most MAX_BODY_LENGTH characters (line endings
 * normalized); a link has an absolute http(s) URL without credentials. Only
 * the field of the chosen format is kept, so a text never carries a stale
 * URL and the other way around.
 */
final readonly class LegalDocumentData
{
    public const int MAX_BODY_LENGTH = 50_000;

    public const int MAX_URL_LENGTH = 2048;

    private function __construct(
        public LegalDocumentKind $kind,
        public LegalDocumentFormat $format,
        public ?string $body,
        public ?string $url,
    ) {}

    /**
     * @throws InvalidLegalDocumentException
     */
    public static function from(LegalDocumentKind $kind, LegalDocumentFormat $format, mixed $body, mixed $url): self
    {
        return $format === LegalDocumentFormat::Text
            ? new self($kind, $format, self::body($body), null)
            : new self($kind, $format, null, self::url($url));
    }

    /**
     * @throws InvalidLegalDocumentException
     */
    private static function body(mixed $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", is_string($body) ? $body : ''));

        if ($body === '') {
            throw new InvalidLegalDocumentException(LegalDocumentRejection::EmptyBody);
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidLegalDocumentException(LegalDocumentRejection::BodyTooLong);
        }

        return $body;
    }

    /**
     * An absolute http(s) URL with a host, no user or password, no spaces or
     * control characters: it is shown to payers as a link that opens in a
     * new tab.
     *
     * @throws InvalidLegalDocumentException
     */
    private static function url(mixed $url): string
    {
        $url = trim(is_string($url) ? $url : '');
        $parts = parse_url($url);

        $valid = $url !== ''
            && strlen($url) <= self::MAX_URL_LENGTH
            && preg_match('/[\s\x00-\x1F\x7F]/', $url) !== 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);

        if (! $valid) {
            throw new InvalidLegalDocumentException(LegalDocumentRejection::InvalidUrl);
        }

        return $url;
    }
}
