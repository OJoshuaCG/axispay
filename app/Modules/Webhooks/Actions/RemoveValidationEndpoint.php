<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Services\ValidationEndpointNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes the pre-payment validation URL of a mode: `webhooks:manage` +
 * re-authentication. The call log stays (it belongs to the links it
 * validated) until its 30-day retention. New links can no longer ask for
 * validation; links created with it are NOT charged until the URL is
 * configured again (fail closed, ADR-0058): the panel warns before removing.
 * Allowed for read-only tenants. E-mails the owners and the users with
 * `webhooks:manage`.
 */
final readonly class RemoveValidationEndpoint
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private ValidationEndpointNotifier $notifier,
    ) {}

    public function handle(User $actor, ValidationEndpoint $endpoint): void
    {
        Gate::forUser($actor)->authorize('delete', $endpoint);
        $this->reauthentication->ensureConfirmed();

        $removed = DB::transaction(function () use ($actor, $endpoint): ?ValidationEndpoint {
            $locked = ValidationEndpoint::query()->lockForUpdate()->find($endpoint->id);

            if ($locked === null) {
                return null; // already removed
            }

            $this->audit->record(AuditAction::ValidationEndpointRemoved, $locked, [
                'host' => $locked->host(),
                'livemode' => $locked->livemode,
            ], actor: Actor::user($actor->id));

            $locked->delete();

            return $locked;
        });

        if ($removed !== null) {
            $this->notifier->notify($removed, ValidationEndpointChange::Removed);
        }
    }
}
