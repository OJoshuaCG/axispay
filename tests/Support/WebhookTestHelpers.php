<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use App\Modules\Webhooks\Services\WebhookSigner;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Shared helpers of the outgoing webhook tests (plan 15).
 */
final class WebhookTestHelpers
{
    public const string URL = 'https://hooks.merchant.example/axispay';

    /**
     * An endpoint written directly (the actions are tested on their own).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function endpoint(Tenant $tenant, bool $livemode = false, array $attributes = []): WebhookEndpoint
    {
        return self::in($tenant, $livemode, static function () use ($livemode, $attributes): WebhookEndpoint {
            $endpoint = new WebhookEndpoint;
            $endpoint->forceFill([
                'livemode' => $livemode,
                'url' => self::URL,
                'description' => null,
                'enabled_events' => ['*'],
                'secret' => app(WebhookSigner::class)->generateSecret(),
                'status' => WebhookEndpointStatus::Enabled,
                ...$attributes,
            ])->save();

            return $endpoint->refresh();
        });
    }

    /**
     * Records a business event the way the domain does: inside a
     * transaction, in the tenant's context.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(Tenant $tenant, DomainEventType $type, array $data = [], bool $livemode = false, string $subjectId = '01J8Z5Q6T4Y0V8KX2M1N5P7R9S'): DomainEvent
    {
        return self::in($tenant, $livemode, static fn (): DomainEvent => DB::transaction(
            static fn (): DomainEvent => app(DomainEventRecorder::class)->record($type, 'payment_link', $subjectId, $data ?: self::linkData()),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function linkData(): array
    {
        return [
            'payment_link' => [
                'id' => 'plink_01J8Z5Q6T4Y0V8KX2M1N5P7R9S',
                'object' => 'payment_link',
                'amount' => '150.00',
                'currency' => 'MXN',
                'metadata' => (object) [],
            ],
            'open_count' => 1,
            'first_open' => true,
        ];
    }

    /**
     * @return list<WebhookDelivery>
     */
    public static function deliveries(Tenant $tenant, bool $livemode = false): array
    {
        return self::in($tenant, $livemode, static fn (): array => array_values(WebhookDelivery::query()->orderBy('created_at')->orderBy('attempt_number')->get()->all()));
    }

    /**
     * @return list<WebhookEvent>
     */
    public static function events(Tenant $tenant, bool $livemode = false): array
    {
        return self::in($tenant, $livemode, static fn (): array => array_values(WebhookEvent::query()->orderBy('id')->get()->all()));
    }

    public static function freshEndpoint(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        return self::in($endpoint->tenant_id, $endpoint->livemode, static fn (): WebhookEndpoint => WebhookEndpoint::query()->findOrFail($endpoint->id));
    }

    /** The first value of a header of a faked request ('' when absent). */
    public static function header(Request $request, string $name): string
    {
        $value = $request->header($name)[0] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The requests the HTTP fake received, in order.
     *
     * @return list<Request>
     */
    public static function sentRequests(): array
    {
        return array_values(Http::recorded()->map(static fn (array $pair): Request => $pair[0])->all());
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function in(Tenant|string $tenant, bool $livemode, Closure $callback): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant instanceof Tenant ? $tenant->id : $tenant, $livemode, $callback);
    }
}
