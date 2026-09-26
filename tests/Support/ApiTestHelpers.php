<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Services\ApiKeyGenerator;
use App\Modules\PaymentLinks\Data\CreatePaymentLinkData;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Database\Factories\PaymentLinkFactory;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;

/**
 * Shared helpers of the API and payment link tests (Phase 3).
 */
final class ApiTestHelpers
{
    /**
     * An API key of `$tenant` in the given mode, and its plaintext.
     *
     * @param  list<ApiScope>|null  $scopes  null = every scope
     * @param  array<string, mixed>  $attributes
     * @return array{0: ApiKey, 1: string}
     */
    public static function key(Tenant $tenant, bool $livemode = false, ?array $scopes = null, array $attributes = []): array
    {
        $generated = app(ApiKeyGenerator::class)->generate($livemode);

        $key = app(TenantContext::class)->runAsTenant($tenant->id, $livemode, static function () use ($generated, $scopes, $attributes): ApiKey {
            $factory = ApiKey::factory()->withPlaintext($generated->plaintext);
            $factory = $scopes !== null ? $factory->scopes($scopes) : $factory;

            return $factory->createOne($attributes);
        });

        return [$key, $generated->plaintext];
    }

    /**
     * A tenant that can create links in `$livemode`: status active (or the
     * given one) and an active gateway connection with charges enabled.
     */
    public static function readyTenant(bool $livemode = false, TenantStatus $status = TenantStatus::Active): Tenant
    {
        $tenant = Tenant::factory()->status($status)->create();
        GatewayTestHelpers::connection($tenant, $livemode);

        return $tenant;
    }

    /**
     * @param  (Closure(PaymentLinkFactory): PaymentLinkFactory)|null  $state
     */
    public static function link(Tenant $tenant, bool $livemode = false, ?Closure $state = null): PaymentLink
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $livemode, static function () use ($state): PaymentLink {
            $factory = PaymentLink::factory();

            return ($state !== null ? $state($factory) : $factory)->createOne();
        });
    }

    /**
     * @return Closure(PaymentLinkFactory): PaymentLinkFactory
     */
    public static function inStatus(PaymentLinkStatus $status): Closure
    {
        return static fn (PaymentLinkFactory $factory): PaymentLinkFactory => $factory->inStatus($status);
    }

    /**
     * The table of a Filament list page, as rendered for the current user.
     *
     * @param  class-string<Component>  $page
     */
    public static function tableOf(string $page): Table
    {
        $instance = Livewire::test($page)->instance();

        return $instance instanceof HasTable
            ? $instance->getTable()
            : throw new LogicException("{$page} has no table.");
    }

    /**
     * A create request already through PaymentLinkInputParser.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function parsedBody(array $overrides = []): CreatePaymentLinkData
    {
        return app(PaymentLinkInputParser::class)->parse(self::body($overrides));
    }

    /**
     * A string field of every item of a list response.
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    public static function listed(TestResponse $response, string $field = 'id'): array
    {
        $data = $response->json('data');
        $values = [];

        foreach (is_array($data) ? $data : [] as $item) {
            $value = is_array($item) ? ($item[$field] ?? null) : null;
            $values[] = is_string($value) ? $value : '';
        }

        return $values;
    }

    /**
     * Whether the query log locks a `$table` row (`SELECT … FOR UPDATE`)
     * before its first UPDATE of that table.
     *
     * @param  array<array-key, mixed>  $log  DB::getQueryLog()
     */
    public static function lockedBeforeUpdate(array $log, string $table): bool
    {
        $lock = null;

        foreach (array_values($log) as $index => $entry) {
            $query = is_array($entry) && is_string($entry['query'] ?? null) ? $entry['query'] : '';

            if ($lock === null && str_contains($query, "from `{$table}`") && str_ends_with($query, 'for update')) {
                $lock = $index;
            }

            if (str_starts_with($query, "update `{$table}`")) {
                return $lock !== null;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $plaintext, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer '.$plaintext, 'Accept' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    /**
     * A request with a raw body (exact bytes, own Content-Type).
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public static function raw(string $method, string $url, array $headers, string $content = ''): TestResponse
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        $response = call($method, $url, [], [], [], $server, $content);

        return $response instanceof TestResponse ? $response : throw new LogicException('Expected a TestResponse.');
    }

    /**
     * A link row read without the tenant scope (assertions only).
     */
    public static function freshLink(string $id): PaymentLink
    {
        return PaymentLink::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * The smallest valid create body; override any field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(array $overrides = []): array
    {
        return [...['amount' => '1500.00', 'currency' => 'USD', 'description' => 'Order #A-1029'], ...$overrides];
    }
}
