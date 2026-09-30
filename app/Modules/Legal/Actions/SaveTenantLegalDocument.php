<?php

declare(strict_types=1);

namespace App\Modules\Legal\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\Legal\Services\LegalAuditChanges;
use App\Modules\Legal\Services\TenantLegalDocuments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the merchant's privacy notice or terms (ADR-0056) as a text or a
 * link, replacing the current one of that kind. Needs `legal:manage` and a
 * writable panel (policy; impersonation is denied there, plan 17.4); audited
 * in the tenant's log. Saving the same document again is a no-op (no audit
 * row). Takes effect on the payment pages at once: with a privacy notice the
 * checkout collects the link's payer fields again (ADR-0051).
 */
final readonly class SaveTenantLegalDocument
{
    public function __construct(
        private AuditLogger $audit,
        private TenantLegalDocuments $documents,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, LegalDocumentData $data): TenantLegalDocument
    {
        Gate::forUser($actor)->authorize('manage', TenantLegalDocument::class);

        try {
            $document = $this->save($actor, $data);
        } catch (UniqueConstraintViolationException) {
            // Two first saves of the same kind at once: the other one won; update it.
            $document = $this->save($actor, $data);
        }

        $this->documents->forget($actor->tenant_id);

        return $document;
    }

    private function save(User $actor, LegalDocumentData $data): TenantLegalDocument
    {
        return DB::transaction(function () use ($actor, $data): TenantLegalDocument {
            $current = TenantLegalDocument::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('kind', $data->kind->value)
                ->lockForUpdate()
                ->first();

            if ($current !== null && LegalAuditChanges::unchanged($current, $data)) {
                return $current;
            }

            $changes = LegalAuditChanges::of($data->kind, $current, $data);
            $document = $current ?? (new TenantLegalDocument)->forceFill(['tenant_id' => $actor->tenant_id, 'kind' => $data->kind]);
            $document->forceFill([
                'format' => $data->format,
                'body' => $data->body,
                'url' => $data->url,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::LegalDocumentUpdated, $document, $changes, tenantId: $actor->tenant_id, actor: Actor::user($actor->id));

            return $document;
        });
    }
}
