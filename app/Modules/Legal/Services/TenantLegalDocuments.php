<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Data\LegalDocument;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\TenantLegalDocument;

/**
 * The merchant's published legal documents (ADR-0056), read through the
 * tenant scope (the checkout and the panel both run in the tenant's
 * context). "Has a privacy notice" means a text or a link is set: without
 * one the checkout collects no payer data (ADR-0051).
 *
 * Scoped (one instance per request or job): a tenant's documents are read
 * once and remembered while a page renders; the Legal actions call forget().
 */
final class TenantLegalDocuments
{
    /** @var array<string, array<string, LegalDocument>> tenant => kind => document */
    private array $documents = [];

    /**
     * @return array<string, LegalDocument> kind => document, in LegalDocumentKind order
     */
    public function all(string $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->documents)) {
            $found = [];

            foreach (TenantLegalDocument::query()->where('tenant_id', $tenantId)->get() as $row) {
                $document = LegalDocument::fromModel($row);

                if ($document->isPublished()) {
                    $found[$document->kind->value] = $document;
                }
            }

            $ordered = [];

            foreach (LegalDocumentKind::cases() as $kind) {
                if (isset($found[$kind->value])) {
                    $ordered[$kind->value] = $found[$kind->value];
                }
            }

            $this->documents[$tenantId] = $ordered;
        }

        return $this->documents[$tenantId];
    }

    public function find(string $tenantId, LegalDocumentKind $kind): ?LegalDocument
    {
        return $this->all($tenantId)[$kind->value] ?? null;
    }

    public function hasPrivacyNotice(string $tenantId): bool
    {
        return $this->find($tenantId, LegalDocumentKind::Privacy) !== null;
    }

    public function forget(string $tenantId): void
    {
        unset($this->documents[$tenantId]);
    }
}
