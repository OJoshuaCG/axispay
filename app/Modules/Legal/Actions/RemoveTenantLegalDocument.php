<?php

declare(strict_types=1);

namespace App\Modules\Legal\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\Legal\Services\LegalAuditChanges;
use App\Modules\Legal\Services\TenantLegalDocuments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes the merchant's privacy notice or terms (ADR-0056). Same guards and
 * audit as SaveTenantLegalDocument. Without a privacy notice the checkout
 * stops collecting payer data (ADR-0051). Removing a document that is not
 * set is a no-op (no audit row).
 */
final readonly class RemoveTenantLegalDocument
{
    public function __construct(
        private AuditLogger $audit,
        private TenantLegalDocuments $documents,
    ) {}

    /**
     * @return bool whether a document was removed
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, LegalDocumentKind $kind): bool
    {
        Gate::forUser($actor)->authorize('manage', TenantLegalDocument::class);

        $removed = DB::transaction(function () use ($actor, $kind): bool {
            $document = TenantLegalDocument::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('kind', $kind->value)
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                return false;
            }

            $document->delete();
            $this->audit->record(AuditAction::LegalDocumentRemoved, $document, LegalAuditChanges::of($kind, $document, null), tenantId: $actor->tenant_id, actor: Actor::user($actor->id));

            return true;
        });

        $this->documents->forget($actor->tenant_id);

        return $removed;
    }
}
