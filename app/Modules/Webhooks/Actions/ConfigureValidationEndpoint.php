<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\Services\TenantLock;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Data\IssuedValidationEndpoint;
use App\Modules\Webhooks\Data\ValidationEndpointData;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Services\DestinationGuard;
use App\Modules\Webhooks\Services\ValidationEndpointNotifier;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Configures the pre-payment validation URL of the panel's current mode
 * (plan 15.8.1): creates it, or changes the existing one (one per mode).
 * `webhooks:manage` + re-authentication (plan 17.3), the URL checked by the
 * SSRF protection (plan 15.7). On creation a secret `whsec_...` of its own
 * (never the webhooks' one) is generated, stored encrypted and returned in
 * plaintext ONCE; an update keeps the secret. E-mails the owners and the
 * users with `webhooks:manage`.
 */
final readonly class ConfigureValidationEndpoint
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private TenantAccess $access,
        private TenantContext $context,
        private TenantLock $tenantLock,
        private DestinationGuard $guard,
        private WebhookSigner $signer,
        private AuditLogger $audit,
        private ValidationEndpointNotifier $notifier,
    ) {}

    /**
     * @throws WebhookEndpointNotAllowedException
     * @throws UnsafeDestinationException
     */
    public function handle(User $actor, ValidationEndpointData $data): IssuedValidationEndpoint
    {
        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        $existing = ValidationEndpoint::query()->first();
        $existing !== null
            ? Gate::forUser($actor)->authorize('update', $existing)
            : Gate::forUser($actor)->authorize('create', ValidationEndpoint::class);
        $this->reauthentication->ensureConfirmed();

        $livemode = $this->context->livemode();
        $url = $this->guard->inspect($data->url, $livemode)->url;

        [$endpoint, $secret] = DB::transaction(function () use ($actor, $data, $livemode, $url): array {
            // Serializes concurrent configurations: one endpoint per mode.
            $this->tenantLock->lock($actor->tenant_id);
            $endpoint = ValidationEndpoint::query()->lockForUpdate()->first();
            $secret = null;

            if ($endpoint === null) {
                $secret = $this->signer->generateSecret();
                $endpoint = new ValidationEndpoint;
                $endpoint->forceFill([
                    'livemode' => $livemode,
                    'secret' => $secret,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $before = $endpoint->exists ? self::auditable($endpoint) : null;

            $endpoint->forceFill([
                'url' => $url,
                'enabled_by_default' => $data->enabledByDefault,
                'failure_policy' => $data->failurePolicy,
            ])->save();

            $this->audit->record(
                $before === null ? AuditAction::ValidationEndpointConfigured : AuditAction::ValidationEndpointUpdated,
                $endpoint,
                array_filter(['before' => $before, 'after' => self::auditable($endpoint), 'livemode' => $livemode], static fn (mixed $v): bool => $v !== null),
                actor: Actor::user($actor->id),
            );

            return [$endpoint, $secret];
        });

        $this->notifier->notify($endpoint, $secret !== null ? ValidationEndpointChange::Configured : ValidationEndpointChange::Updated);

        return new IssuedValidationEndpoint($endpoint, $secret, $secret !== null);
    }

    /**
     * The URL's host only (the path may carry the merchant's tokens).
     *
     * @return array<string, mixed>
     */
    private static function auditable(ValidationEndpoint $endpoint): array
    {
        return [
            'host' => $endpoint->host(),
            'enabled_by_default' => $endpoint->enabled_by_default,
            'failure_policy' => $endpoint->failure_policy->value,
        ];
    }
}
