<?php

declare(strict_types=1);

namespace App\Modules\Legal\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Services\LegalAuditChanges;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the platform's privacy notice or terms (ADR-0056) as a text or a
 * link. Only holders of `platform:legal:manage`, inside the
 * re-authentication window, like the platform brand (ADR-0053); audited in
 * the platform log. Saving the same document again is a no-op.
 */
final readonly class SavePlatformLegalDocument
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private PlatformLegalDocuments $documents,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ReauthenticationRequiredException
     */
    public function handle(PlatformAdmin $actor, LegalDocumentData $data): PlatformLegalDocument
    {
        Gate::forUser($actor)->authorize('manage', PlatformLegalDocument::class);
        $this->reauthentication->ensureConfirmed();

        try {
            $document = $this->save($actor, $data);
        } catch (UniqueConstraintViolationException) {
            $document = $this->save($actor, $data);
        }

        $this->documents->forget();

        return $document;
    }

    private function save(PlatformAdmin $actor, LegalDocumentData $data): PlatformLegalDocument
    {
        return DB::transaction(function () use ($actor, $data): PlatformLegalDocument {
            $current = PlatformLegalDocument::query()->where('kind', $data->kind->value)->lockForUpdate()->first();

            if ($current !== null && LegalAuditChanges::unchanged($current, $data)) {
                return $current;
            }

            $changes = LegalAuditChanges::of($data->kind, $current, $data);
            $document = $current ?? (new PlatformLegalDocument)->forceFill(['kind' => $data->kind]);
            $document->forceFill([
                'format' => $data->format,
                'body' => $data->body,
                'url' => $data->url,
                'updated_by_platform_admin_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::PlatformLegalDocumentUpdated, $document, $changes, platform: true, actor: Actor::platformAdmin($actor->id));

            return $document;
        });
    }
}
