<?php

declare(strict_types=1);

namespace App\Modules\Legal\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Services\LegalAuditChanges;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes the platform's privacy notice or terms (ADR-0056). Same guards and
 * audit as SavePlatformLegalDocument. Without either document, `/legal`
 * answers 404 and the checkout footer stops linking to it. Removing a
 * document that is not set is a no-op.
 */
final readonly class RemovePlatformLegalDocument
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private PlatformLegalDocuments $documents,
    ) {}

    /**
     * @return bool whether a document was removed
     *
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     */
    public function handle(PlatformAdmin $actor, LegalDocumentKind $kind): bool
    {
        Gate::forUser($actor)->authorize('manage', PlatformLegalDocument::class);
        $this->reauthentication->ensureConfirmed();

        $removed = DB::transaction(function () use ($actor, $kind): bool {
            $document = PlatformLegalDocument::query()->where('kind', $kind->value)->lockForUpdate()->first();

            if ($document === null) {
                return false;
            }

            $document->delete();
            $this->audit->record(AuditAction::PlatformLegalDocumentRemoved, $document, LegalAuditChanges::of($kind, $document, null), platform: true, actor: Actor::platformAdmin($actor->id));

            return true;
        });

        $this->documents->forget();

        return $removed;
    }
}
