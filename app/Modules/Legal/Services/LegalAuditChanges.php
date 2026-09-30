<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Models\TenantLegalDocument;

/**
 * What the audit log keeps of a legal document change (ADR-0056): the kind,
 * the format and the link before and after, and for texts only their length
 * and SHA-256 (`*_hash` keys, kept intact by AuditLogger), never the text
 * itself: the log stays small and the text's history is not duplicated there.
 */
final class LegalAuditChanges
{
    /**
     * @return array<string, mixed>
     */
    public static function of(LegalDocumentKind $kind, TenantLegalDocument|PlatformLegalDocument|null $before, ?LegalDocumentData $after): array
    {
        return [
            'kind' => $kind->value,
            ...self::side('before', $before?->format->value, $before?->body, $before?->url),
            ...self::side('after', $after?->format->value, $after?->body, $after?->url),
        ];
    }

    /** Whether saving $after would change nothing. */
    public static function unchanged(TenantLegalDocument|PlatformLegalDocument|null $before, LegalDocumentData $after): bool
    {
        return $before !== null
            && $before->format === $after->format
            && $before->body === $after->body
            && $before->url === $after->url;
    }

    /**
     * @return array<string, mixed>
     */
    private static function side(string $side, ?string $format, ?string $body, ?string $url): array
    {
        $changes = ["format_{$side}" => $format];

        if ($url !== null) {
            $changes["url_{$side}"] = $url;
        }

        if ($body !== null) {
            $changes["body_length_{$side}"] = mb_strlen($body);
            $changes["body_{$side}_hash"] = hash('sha256', $body);
        }

        return $changes;
    }
}
