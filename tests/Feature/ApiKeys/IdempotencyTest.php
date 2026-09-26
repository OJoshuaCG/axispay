<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Data\IdempotentRequest;
use App\Modules\ApiKeys\Models\IdempotencyRecord;
use App\Modules\ApiKeys\Services\IdempotencyRecordRetention;
use App\Modules\ApiKeys\Services\IdempotencyStore;
use App\Modules\ApiKeys\Services\RequestFingerprint;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * Idempotency of the API (plan 7.8, 10.3; critical case 15).
 */
function linkCount(): int
{
    return PaymentLink::query()->withoutGlobalScopes()->count();
}

it('requires an Idempotency-Key to create a link', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body())
        ->assertStatus(400)
        ->assertJsonPath('error.code', ApiErrorCode::IdempotencyKeyRequired->value)
        ->assertJsonPath('error.param', 'Idempotency-Key');
    expect(linkCount())->toBe(0);
});

it('rejects a malformed Idempotency-Key', function (string $idempotencyKey): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, $idempotencyKey))
        ->assertStatus(400)
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', 'Idempotency-Key');
})->with([
    'space' => ['order 1'],
    'slash' => ['order/1'],
    'too long' => [str_repeat('k', 256)],
    'unicode' => ['pedido-ñ'],
]);

it('replays the original response for the same key and body', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    $first = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'order-A-1029:v1'))
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    // Same body with other key order and whitespace is the same request.
    $second = ApiTestHelpers::raw('POST', apiUrl('v1/payment_links'), [
        ...ApiTestHelpers::headers($key, 'order-A-1029:v1'),
        'Content-Type' => 'application/json',
    ], '{ "description": "Order #A-1029", "currency": "USD",  "amount": "1500.00" }');

    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($second->getContent())->toBe($first->getContent())
        ->and(linkCount())->toBe(1);
});

it('answers 422 idempotency_key_reused for the same key with another body (case 15)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'reuse-me'))->assertCreated();

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => '1600.00']), ApiTestHelpers::headers($key, 'reuse-me'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', ApiErrorCode::IdempotencyKeyReused->value)
        ->assertJsonPath('error.type', 'invalid_request_error');
    expect(linkCount())->toBe(1);
});

it('treats the same key on another endpoint as reused', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant);
    postJson(apiUrl('v1/payment_links'), [], ApiTestHelpers::headers($key, 'same-key'))->assertStatus(400);

    postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), [], ApiTestHelpers::headers($key, 'same-key'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', ApiErrorCode::IdempotencyKeyReused->value);
});

it('replays stored 4xx answers as well', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    $first = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => 15]), ApiTestHelpers::headers($key, 'bad-amount'))->assertStatus(400);

    $replay = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => 15]), ApiTestHelpers::headers($key, 'bad-amount'))
        ->assertStatus(400)
        ->assertHeader('Idempotent-Replayed', 'true');
    expect($replay->getContent())->toBe($first->getContent());
});

it('answers 409 while a request with the same key is still running, simulated with an in-flight row', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    // What a concurrent request sees between the claim and the response:
    // a row with a live lock and no response yet. The truly concurrent race
    // runs in Feature/Concurrency against separate processes.
    app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($apiKey): void {
        app(IdempotencyStore::class)->begin(new IdempotentRequest(
            $apiKey->id,
            'in-flight',
            'POST',
            '/v1/payment_links',
            app(RequestFingerprint::class)->of((string) json_encode(ApiTestHelpers::body())),
        ));
    });

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'in-flight'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', ApiErrorCode::IdempotencyRequestInProgress->value);
    expect(linkCount())->toBe(0);
});

it('takes over a key whose request died before answering', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');
    app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($apiKey): void {
        app(IdempotencyStore::class)->begin(new IdempotentRequest(
            $apiKey->id,
            'crashed',
            'POST',
            '/v1/payment_links',
            app(RequestFingerprint::class)->of((string) json_encode(ApiTestHelpers::body())),
        ));
    });

    Carbon::setTestNow('2026-09-26 12:05:01');

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'crashed'))->assertCreated();
    expect(linkCount())->toBe(1);
});

it('does not store 5xx answers, so the client can retry', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    $fail = true;
    PaymentLink::creating(static function () use (&$fail): void {
        if ($fail) {
            $fail = false;

            throw new RuntimeException('database went away');
        }
    });

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'retry-after-500'))
        ->assertStatus(500)
        ->assertJsonPath('error.code', ApiErrorCode::InternalError->value);
    expect(IdempotencyRecord::query()->withoutGlobalScopes()->count())->toBe(0);

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'retry-after-500'))->assertCreated();
});

it('after 24 hours returns the same link for the same body and refuses another body', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');
    $first = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'daily'))->assertCreated();

    Carbon::setTestNow('2026-09-27 12:00:01');
    artisanCommand('axispay:idempotency:purge')->assertSuccessful();

    // Another body with the old key: refused, even though the record expired.
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => '10.00']), ApiTestHelpers::headers($key, 'daily'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', ApiErrorCode::IdempotencyKeyReused->value);

    // The same body (any key order) with the old key: the existing link, not a second one.
    postJson(apiUrl('v1/payment_links'), ['description' => 'Order #A-1029', 'currency' => 'USD', 'amount' => '1500.00'], ApiTestHelpers::headers($key, 'daily-2'))->assertCreated();
    Carbon::setTestNow('2026-09-28 12:00:02');
    artisanCommand('axispay:idempotency:purge')->assertSuccessful();

    $again = postJson(apiUrl('v1/payment_links'), ['currency' => 'USD', 'amount' => '1500.00', 'description' => 'Order #A-1029'], ApiTestHelpers::headers($key, 'daily'))
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect($again->json('id'))->toBe($first->json('id'))
        ->and(linkCount())->toBe(2);
});

it('replays an empty-body cancel for {} and [] bodies with the same key', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    $url = apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel');

    ApiTestHelpers::raw('POST', $url, [...ApiTestHelpers::headers($key, 'empty-cancel')])->assertOk();
    ApiTestHelpers::raw('POST', $url, [...ApiTestHelpers::headers($key, 'empty-cancel'), 'Content-Type' => 'application/json'], '{}')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    ApiTestHelpers::raw('POST', $url, [...ApiTestHelpers::headers($key, 'empty-cancel'), 'Content-Type' => 'application/json'], '[]')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
});

it('scopes keys per tenant and mode', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    $other = ApiTestHelpers::readyTenant();
    [, $otherKey] = ApiTestHelpers::key($other);

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'shared-key'))->assertCreated();
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => '99.00']), ApiTestHelpers::headers($otherKey, 'shared-key'))->assertCreated();

    expect(linkCount())->toBe(2);
});

it('purges expired records with the scheduled command', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'old'))->assertCreated();
    Carbon::setTestNow('2026-09-27 11:00:00');
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => '11.00']), ApiTestHelpers::headers($key, 'recent'))->assertCreated();
    Carbon::setTestNow('2026-09-27 12:30:00');

    artisanCommand('axispay:idempotency:purge')->expectsOutputToContain('Deleted 1 expired idempotency record(s).')->assertSuccessful();

    expect(IdempotencyRecord::query()->withoutGlobalScopes()->pluck('idempotency_key')->all())->toBe(['recent']);
});

it('makes the cancel endpoint idempotent when a key is sent', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant);

    postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ['reason' => 'x'], ApiTestHelpers::headers($key, 'cancel-1'))->assertOk();
    postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'), ['reason' => 'x'], ApiTestHelpers::headers($key, 'cancel-1'))
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');
});

it('never lets a request that lost its key overwrite or delete the new owner\'s row', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);
    $request = new IdempotentRequest($apiKey->id, 'contested', 'POST', '/v1/payment_links', app(RequestFingerprint::class)->of('{}'));
    $store = app(IdempotencyStore::class);
    $log = captureDefaultLog();

    app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($store, $request): void {
        $slow = $store->begin($request);

        // The slow request runs past the lock; another one takes the key over.
        Carbon::setTestNow('2026-09-26 12:05:01');
        $newOwner = $store->begin($request);
        expect($newOwner->replay)->toBeFalse()->and($newOwner->lockToken)->not->toBe($slow->lockToken);

        // The slow one finishes: it can neither store nor release.
        expect($store->complete($slow, 201, '{"stale":true}'))->toBeFalse()
            ->and($store->release($slow))->toBeFalse();

        $row = IdempotencyRecord::query()->sole();
        expect($row->response_status)->toBeNull()->and($row->lock_token)->toBe($newOwner->lockToken);

        expect($store->complete($newOwner, 201, '{"fresh":true}'))->toBeTrue();
        expect(IdempotencyRecord::query()->sole()->response_body)->toBe('{"fresh":true}');

        // A stored answer cannot be released or overwritten any more.
        expect($store->release($newOwner))->toBeFalse();
    });

    expect($log->hasWarningThatContains('Idempotency key lost before its answer could be stored.'))->toBeTrue()
        ->and($log->hasWarningThatContains('Idempotency key lost before it could be released.'))->toBeTrue();
});

it('keeps an unfinished request\'s key locked for 5 minutes, then lets it be taken over', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);
    $request = new IdempotentRequest($apiKey->id, 'slow', 'POST', '/v1/payment_links', app(RequestFingerprint::class)->of('{}'));
    $store = app(IdempotencyStore::class);

    app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($store, $request): void {
        $store->begin($request);

        Carbon::setTestNow('2026-09-26 12:04:59');
        expect(fn () => $store->begin($request))->toThrow(ApiException::class, ApiErrorCode::IdempotencyRequestInProgress->defaultMessage());

        Carbon::setTestNow('2026-09-26 12:05:01');
        expect($store->begin($request)->replay)->toBeFalse();
    });
});

it('stores any answer body, JSON or not', function (string $body): void {
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);

    app(TenantContext::class)->runAsTenant($tenant->id, false, static function () use ($apiKey, $body): void {
        $store = app(IdempotencyStore::class);
        $decision = $store->begin(new IdempotentRequest($apiKey->id, 'body-'.md5($body), 'POST', '/v1/x', app(RequestFingerprint::class)->of('{}')));

        expect($store->complete($decision, 200, $body))->toBeTrue()
            ->and(IdempotencyRecord::query()->sole()->response_body)->toBe($body);
    });
})->with(['empty' => [''], 'plain text' => ['not json'], 'json' => ['{"ok":true}']]);

it('purges more than one chunk of expired records', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [$apiKey] = ApiTestHelpers::key($tenant);
    $rows = [];

    foreach (range(1, 1002) as $i) {
        $rows[] = [
            'id' => Ulid::generate(),
            'tenant_id' => $tenant->id,
            'livemode' => false,
            'api_key_id' => $apiKey->id,
            'idempotency_key' => "old-{$i}",
            'request_method' => 'POST',
            'request_path' => '/v1/payment_links',
            'request_hash' => str_repeat('a', 64),
            'expires_at' => '2026-09-25 11:00:00.000000',
            'created_at' => '2026-09-24 11:00:00.000000',
            'updated_at' => '2026-09-24 11:00:00.000000',
        ];
    }

    IdempotencyRecord::query()->withoutGlobalScopes()->insert($rows);

    expect(app(IdempotencyRecordRetention::class)->purge())->toBe(1002)
        ->and(IdempotencyRecord::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('treats a record past its 24 hours as gone even before the purge runs', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $first = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'stale-key'))->assertCreated();
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => 15]), ApiTestHelpers::headers($key, 'stale-400'))->assertStatus(400);

    Carbon::setTestNow('2026-09-27 12:01:00');

    // Same body: the same link, not replayed from the record and not a new link.
    $again = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'stale-key'))
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');
    expect($again->json('id'))->toBe($first->json('id'))->and(linkCount())->toBe(1);

    // Another body: still refused, the key created a link.
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => '10.00']), ApiTestHelpers::headers($key, 'stale-key'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', ApiErrorCode::IdempotencyKeyReused->value);

    // A stored 4xx is executed again, not replayed.
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => 15]), ApiTestHelpers::headers($key, 'stale-400'))
        ->assertStatus(400)
        ->assertHeaderMissing('Idempotent-Replayed');
});

it('keeps idempotency keys apart per mode for the same tenant', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    GatewayTestHelpers::connection($tenant, livemode: true);
    [, $test] = ApiTestHelpers::key($tenant, livemode: false);
    [, $live] = ApiTestHelpers::key($tenant, livemode: true);

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($test, 'order-1'))->assertCreated()->assertJsonPath('livemode', false);
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['amount' => '20.00']), ApiTestHelpers::headers($live, 'order-1'))
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('livemode', true);

    expect(linkCount())->toBe(2);
});

it('shares the idempotency namespace between two keys of the same tenant and mode', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $first] = ApiTestHelpers::key($tenant);
    [, $second] = ApiTestHelpers::key($tenant);

    $created = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($first, 'shared-ns'))->assertCreated();

    $replay = postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($second, 'shared-ns'))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');
    expect($replay->getContent())->toBe($created->getContent())->and(linkCount())->toBe(1);
});

it('keeps replaying a stored 403 tenant_suspended after the tenant is reactivated (ADR-0048 §5)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'while-suspended'))->assertForbidden();
    $tenant->forceFill(['status' => TenantStatus::Active])->save();

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'while-suspended'))
        ->assertForbidden()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('error.code', ApiErrorCode::TenantSuspended->value);
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, 'after-reactivation'))->assertCreated();
});

it('accepts a 255-character key made of every allowed character', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $idempotencyKey = str_pad('Order_A-1029:v1.', 255, 'x');

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, $idempotencyKey))->assertCreated();
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), ApiTestHelpers::headers($key, $idempotencyKey))->assertHeader('Idempotent-Replayed', 'true');
});
