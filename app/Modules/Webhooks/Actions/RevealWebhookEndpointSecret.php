<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Gate;

/**
 * Shows an endpoint's current secret again (plan 15.1 "reveal"):
 * `webhooks:manage` + re-authentication (plan 17.3), audited. Never
 * available while impersonating (the window cannot be confirmed then).
 */
final readonly class RevealWebhookEndpointSecret
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, WebhookEndpoint $endpoint): string
    {
        Gate::forUser($actor)->authorize('revealSecret', $endpoint);
        $this->reauthentication->ensureConfirmed();

        $fresh = WebhookEndpoint::query()->findOrFail($endpoint->id);

        $this->audit->record(AuditAction::WebhookEndpointSecretRevealed, $fresh, [
            'host' => $fresh->host(),
            'livemode' => $fresh->livemode,
        ], actor: Actor::user($actor->id));

        return $fresh->secret;
    }
}
