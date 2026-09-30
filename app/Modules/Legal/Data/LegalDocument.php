<?php

declare(strict_types=1);

namespace App\Modules\Legal\Data;

use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Models\TenantLegalDocument;

/**
 * A published legal document as the pages show it (ADR-0056): a text (the
 * Markdown source; LegalMarkdown renders it) or a link to an external page.
 */
final readonly class LegalDocument
{
    public function __construct(
        public LegalDocumentKind $kind,
        public LegalDocumentFormat $format,
        public ?string $body,
        public ?string $url,
    ) {}

    public static function fromModel(TenantLegalDocument|PlatformLegalDocument $document): self
    {
        return new self($document->kind, $document->format, $document->body, $document->url);
    }

    public function isText(): bool
    {
        return $this->format === LegalDocumentFormat::Text && $this->body !== null && $this->body !== '';
    }

    public function isUrl(): bool
    {
        return $this->format === LegalDocumentFormat::Url && $this->url !== null && $this->url !== '';
    }

    /** Something a payer can read: a non-empty text or a link. */
    public function isPublished(): bool
    {
        return $this->isText() || $this->isUrl();
    }
}
