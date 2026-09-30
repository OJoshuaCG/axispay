<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Data\LegalDocument;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\PlatformLegalDocument;
use Illuminate\Contracts\Cache\Repository;

/**
 * The platform's published legal documents (ADR-0056), shown on the pay
 * host's `/legal` page; the checkout footer links there when at least one
 * exists. Read from the cache and remembered for the rest of the request
 * (every payment page asks), like the platform brand; every change calls
 * forget().
 */
final class PlatformLegalDocuments
{
    private const string CACHE_KEY = 'legal:platform:v1';

    /** @var array<string, LegalDocument>|null */
    private ?array $documents = null;

    public function __construct(private readonly Repository $cache) {}

    /**
     * @return array<string, LegalDocument> kind => document, in LegalDocumentKind order
     */
    public function all(): array
    {
        if ($this->documents !== null) {
            return $this->documents;
        }

        /** @var mixed $cached */
        $cached = $this->cache->rememberForever(self::CACHE_KEY, static function (): array {
            $rows = [];

            foreach (PlatformLegalDocument::query()->get() as $row) {
                $rows[$row->kind->value] = ['format' => $row->format->value, 'body' => $row->body, 'url' => $row->url];
            }

            return $rows;
        });

        $documents = [];

        foreach (LegalDocumentKind::cases() as $kind) {
            $row = is_array($cached) && is_array($cached[$kind->value] ?? null) ? $cached[$kind->value] : null;
            $format = $row !== null && is_string($row['format'] ?? null) ? LegalDocumentFormat::tryFrom($row['format']) : null;

            if ($row === null || $format === null) {
                continue;
            }

            $document = new LegalDocument(
                $kind,
                $format,
                is_string($row['body'] ?? null) ? $row['body'] : null,
                is_string($row['url'] ?? null) ? $row['url'] : null,
            );

            if ($document->isPublished()) {
                $documents[$kind->value] = $document;
            }
        }

        return $this->documents = $documents;
    }

    public function find(LegalDocumentKind $kind): ?LegalDocument
    {
        return $this->all()[$kind->value] ?? null;
    }

    public function hasAny(): bool
    {
        return $this->all() !== [];
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->documents = null;
    }
}
