<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * POST /v1/payment_links (plan 10.5, 8.2, 21.3; critical cases 12 and 14).
 */

/**
 * @param  array<array-key, mixed>  $body
 * @return TestResponse<Response>
 */
function createLink(string $key, array $body, ?string $idempotencyKey = null): TestResponse
{
    return postJson(apiUrl('v1/payment_links'), $body, ApiTestHelpers::headers($key, $idempotencyKey ?? 'idem-'.bin2hex(random_bytes(6))));
}

/**
 * @param  TestResponse<Response>  $response
 */
function expectApiError(TestResponse $response, ApiErrorCode $code, ?string $param = null): void
{
    $response->assertStatus($code->httpStatus())
        ->assertJsonPath('error.code', $code->value)
        ->assertJsonPath('error.type', $code->type()->value)
        ->assertJsonPath('error.param', $param)
        ->assertJsonPath('error.request_id', $response->headers->get('Request-Id'));
}

it('creates a link and answers the payment_link object', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 18:30:00');

    $response = createLink($key, ApiTestHelpers::body([
        'metadata' => ['order_id' => 'A-1029', 'customer_id' => 'C-77'],
        'client_reference_id' => 'A-1029',
        'expires_in_hours' => 72,
        'payer_fields' => ['email' => 'required', 'phone' => 'optional'],
        'locale' => 'en',
    ]));

    $response->assertCreated()
        ->assertHeader('Request-Id')
        ->assertJsonPath('object', 'payment_link')
        ->assertJsonPath('livemode', false)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('amount', '1500.00')
        ->assertJsonPath('amount_minor', 150000)
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('description', 'Order #A-1029')
        ->assertJsonPath('metadata.order_id', 'A-1029')
        ->assertJsonPath('client_reference_id', 'A-1029')
        ->assertJsonPath('fx', ['mode' => 'none', 'rate' => null])
        ->assertJsonPath('payer_fields.email', 'required')
        ->assertJsonPath('payer_fields.phone', 'optional')
        ->assertJsonPath('payer_fields.full_name', 'hidden')
        ->assertJsonPath('locale', 'en')
        ->assertJsonPath('pre_payment_validation', false)
        ->assertJsonPath('expires_at', '2026-09-29T18:30:00Z')
        ->assertJsonPath('created_at', '2026-09-26T18:30:00Z')
        ->assertJsonPath('paid_at', null)
        ->assertJsonPath('refund_status', 'none')
        ->assertJsonPath('dispute_status', 'none')
        ->assertJsonPath('open_count', 0)
        ->assertJsonPath('payment', null);

    $id = $response->json('id');
    expect($id)->toBeString()->toStartWith('plink_');
    assert(is_string($id));

    $link = ApiTestHelpers::freshLink(substr($id, 6));
    expect($link->public_token)->toMatch('/^[0-9A-Za-z]{43}$/')
        ->and($response->json('url'))->toBe('https://pay.localhost/l/'.$link->public_token)
        ->and($link->status)->toBe(PaymentLinkStatus::Active)
        ->and($link->created_via)->toBe(CreatedVia::Api)
        ->and($link->created_by_actor_type)->toBe(ActorType::ApiKey)
        ->and($link->tenant_id)->toBe($tenant->id)
        ->and($link->livemode)->toBeFalse();

    // No gateway identifiers in the public object (ADR-019).
    expect((string) $response->getContent())->not->toContain('acct_');
    expect((string) $response->getContent())->not->toContain('pi_');

    $audit = AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentLinkCreated->value)->sole();
    expect($audit->actor_type)->toBe(ActorType::ApiKey);
});

it('uses the tenant defaults when optional fields are omitted', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant->forceFill(['settings' => [
        'links' => ['default_expiration_hours' => 24],
        'checkout' => ['locale' => 'en'],
        'payer_fields' => ['full_name' => 'required'],
    ]])->save();

    createLink($key, ApiTestHelpers::body())
        ->assertCreated()
        ->assertJsonPath('expires_at', '2026-09-27T12:00:00Z')
        ->assertJsonPath('locale', 'en')
        ->assertJsonPath('payer_fields.email', 'optional')
        ->assertJsonPath('payer_fields.full_name', 'required')
        ->assertJsonPath('metadata', []);
});

it('defaults to 7 days of validity', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');

    createLink($key, ApiTestHelpers::body())->assertCreated()->assertJsonPath('expires_at', '2026-10-03T12:00:00Z');
});

it('normalizes a lowercase currency and accepts an exact expires_at', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');

    createLink($key, ApiTestHelpers::body(['currency' => 'mxn', 'amount' => '10', 'expires_at' => '2026-09-26T08:30:00-06:00']))
        ->assertCreated()
        ->assertJsonPath('currency', 'MXN')
        ->assertJsonPath('amount', '10.00')
        ->assertJsonPath('expires_at', '2026-09-26T14:30:00Z');
});

it('rejects invalid input with the documented envelope, code and param', function (array $body, ApiErrorCode $code, ?string $param): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    expectApiError(createLink($key, $body), $code, $param);
    expect(PaymentLink::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'amount as JSON number (case 14)' => [ApiTestHelpers::body(['amount' => 1500.5]), ApiErrorCode::AmountMustBeString, 'amount'],
    'amount missing' => [['currency' => 'USD', 'description' => 'x'], ApiErrorCode::ParameterMissing, 'amount'],
    'unsupported currency' => [ApiTestHelpers::body(['currency' => 'EUR']), ApiErrorCode::CurrencyNotSupported, 'currency'],
    'unknown parameter' => [ApiTestHelpers::body(['expire_in_hours' => 5]), ApiErrorCode::ParameterInvalid, 'expire_in_hours'],
    'metadata as a list' => [ApiTestHelpers::body(['metadata' => ['a', 'b']]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'expires_in_hours above 90 days' => [ApiTestHelpers::body(['expires_in_hours' => 2161]), ApiErrorCode::ExpirationOutOfRange, 'expires_in_hours'],
    'expires_at in the past' => [ApiTestHelpers::body(['expires_at' => '2020-01-01T00:00:00Z']), ApiErrorCode::ExpirationOutOfRange, 'expires_at'],
    'fx on an MXN link (rule 3)' => [ApiTestHelpers::body(['currency' => 'MXN', 'fx' => ['mode' => 'banxico_fix']]), ApiErrorCode::FxNotAvailable, 'fx.mode'],
    'fx while the tenant has it off (rule 4)' => [ApiTestHelpers::body(['fx' => ['mode' => 'banxico_fix']]), ApiErrorCode::FxNotAvailable, 'fx.mode'],
    'return_url on a foreign domain' => [ApiTestHelpers::body(['return_url' => 'https://evil.example.org/']), ApiErrorCode::ReturnUrlNotAllowed, 'return_url'],
    'pre_payment_validation without a validation URL' => [ApiTestHelpers::body(['pre_payment_validation' => true]), ApiErrorCode::ValidationEndpointNotConfigured, 'pre_payment_validation'],
]);

it('accepts the smallest and largest amounts of each currency', function (string $currency, string $amount, int $minor): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    createLink($key, ApiTestHelpers::body(['currency' => $currency, 'amount' => $amount]))
        ->assertCreated()
        ->assertJsonPath('amount_minor', $minor);
})->with([
    ['USD', '0.50', 50],
    ['USD', '10000.00', 1_000_000],
    ['MXN', '10.00', 1_000],
    ['MXN', '200000', 20_000_000],
    ['USD', '0.5', 50],
]);

it('accepts pre_payment_validation false and stores it off', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    createLink($key, ApiTestHelpers::body(['pre_payment_validation' => false]))
        ->assertCreated()
        ->assertJsonPath('pre_payment_validation', false);
});

it('accepts fx mode none on any currency', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    createLink($key, ApiTestHelpers::body(['currency' => 'MXN', 'amount' => '100.00', 'fx' => ['mode' => 'none']]))
        ->assertCreated()
        ->assertJsonPath('fx.mode', 'none');
});

it('refuses conversion until the platform offers it, even when the tenant enabled it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant->forceFill(['settings' => ['fx' => ['conversion_enabled' => true]]])->save();

    expectApiError(createLink($key, ApiTestHelpers::body(['fx' => ['mode' => 'banxico_fix']])), ApiErrorCode::FxNotAvailable, 'fx.mode');
    createLink($key, ApiTestHelpers::body())->assertCreated()->assertJsonPath('fx.mode', 'none');
});

it('stores the conversion mode once the platform and the tenant enable it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    config(['axispay.fx.available' => true]);
    $tenant->forceFill(['settings' => ['fx' => ['conversion_enabled' => true]]])->save();

    createLink($key, ApiTestHelpers::body(['fx' => ['mode' => 'fixed', 'rate' => '17.25']]))
        ->assertCreated()
        ->assertJsonPath('fx', ['mode' => 'fixed', 'rate' => '17.250000']);
    createLink($key, ApiTestHelpers::body())->assertCreated()->assertJsonPath('fx.mode', 'banxico_fix');
    createLink($key, ApiTestHelpers::body(['currency' => 'MXN', 'amount' => '100.00']))->assertCreated()->assertJsonPath('fx.mode', 'none');
});

it('applies the tenant limits for amount and expiration, never above the platform', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant->forceFill(['settings' => ['links' => [
        'max_expiration_hours' => 48,
        'max_amount_minor' => ['USD' => 50_000, 'MXN' => 999_999_999],
    ]]])->save();

    expectApiError(createLink($key, ApiTestHelpers::body(['amount' => '500.01'])), ApiErrorCode::AmountAboveMaximum, 'amount');
    createLink($key, ApiTestHelpers::body(['amount' => '500.00', 'expires_in_hours' => 48]))->assertCreated();
    expectApiError(createLink($key, ApiTestHelpers::body(['amount' => '10.00', 'expires_in_hours' => 49])), ApiErrorCode::ExpirationOutOfRange, 'expires_in_hours');
    // A tenant cap above the platform cap is clamped to the platform's.
    expectApiError(createLink($key, ApiTestHelpers::body(['currency' => 'MXN', 'amount' => '200000.01'])), ApiErrorCode::AmountAboveMaximum, 'amount');
});

it('enforces the 15-minute minimum expiration', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 12:00:00');

    expectApiError(createLink($key, ApiTestHelpers::body(['expires_at' => '2026-09-26T12:14:59Z'])), ApiErrorCode::ExpirationOutOfRange, 'expires_at');
    createLink($key, ApiTestHelpers::body(['expires_at' => '2026-09-26T12:15:00Z']))->assertCreated();
});

it('allows a return URL on an allowed domain, HTTPS only in live mode', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant->forceFill(['allowed_return_domains' => ['Shop.Example.com']])->save();

    createLink($key, ApiTestHelpers::body(['return_url' => 'https://shop.example.com/thanks?order=A-1029']))
        ->assertCreated()
        ->assertJsonPath('return_url', 'https://shop.example.com/thanks?order=A-1029');
    createLink($key, ApiTestHelpers::body(['return_url' => 'http://shop.example.com/thanks']))->assertCreated();
    expectApiError(createLink($key, ApiTestHelpers::body(['return_url' => 'https://sub.shop.example.com/'])), ApiErrorCode::ReturnUrlNotAllowed, 'return_url');

    $live = ApiTestHelpers::readyTenant(livemode: true);
    $live->forceFill(['allowed_return_domains' => ['shop.example.com']])->save();
    [, $liveKey] = ApiTestHelpers::key($live, livemode: true);

    expectApiError(createLink($liveKey, ApiTestHelpers::body(['return_url' => 'http://shop.example.com/thanks'])), ApiErrorCode::ReturnUrlNotAllowed, 'return_url');
    createLink($liveKey, ApiTestHelpers::body(['return_url' => 'https://shop.example.com/thanks']))->assertCreated()->assertJsonPath('livemode', true);
});

it('refuses to create links for a suspended or closed tenant (case 12)', function (TenantStatus $status): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant->forceFill(['status' => $status, 'status_changed_at' => now(), 'closed_at' => $status === TenantStatus::Closed ? now() : null])->save();

    expectApiError(createLink($key, ApiTestHelpers::body()), ApiErrorCode::TenantSuspended);
})->with([TenantStatus::Suspended, TenantStatus::Closed]);

it('lets a suspended tenant read its links (plan §21.3)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant);
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$link->prefixedId()))->assertOk();
});

it('lets a tenant in grace create links', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant->forceFill(['status' => TenantStatus::Grace])->save();

    createLink($key, ApiTestHelpers::body())->assertCreated();
});

it('answers gateway_not_ready for a tenant still onboarding', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant->forceFill(['status' => TenantStatus::PendingOnboarding])->save();

    expectApiError(createLink($key, ApiTestHelpers::body()), ApiErrorCode::GatewayNotReady);
});

it('answers gateway_not_ready without a connection that can charge in this mode', function (Closure $setup): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $tenant = Tenant::factory()->status(TenantStatus::Active)->create();
    $setup($tenant);
    [, $key] = ApiTestHelpers::key($tenant);

    expectApiError(createLink($key, ApiTestHelpers::body()), ApiErrorCode::GatewayNotReady);
})->with([
    'no connection' => static fn (Tenant $tenant) => null,
    'live connection only' => static fn (Tenant $tenant) => GatewayTestHelpers::connection($tenant, livemode: true),
    'still onboarding' => static fn (Tenant $tenant) => GatewayTestHelpers::connection($tenant, state: static fn ($f) => $f->onboarding()),
    'restricted' => static fn (Tenant $tenant) => GatewayTestHelpers::connection($tenant, state: static fn ($f) => $f->state(['status' => ConnectionStatus::Restricted, 'charges_enabled' => false])),
    'disconnected' => static fn (Tenant $tenant) => GatewayTestHelpers::connection($tenant, state: static fn ($f) => $f->disconnected()),
    'active without charges' => static fn (Tenant $tenant) => GatewayTestHelpers::connection($tenant, state: static fn ($f) => $f->state(['charges_enabled' => false])),
]);

it('creates links with a test-mode api_key connection of an account Stripe has not activated (ADR-0055)', function (): void {
    $tenant = Tenant::factory()->status(TenantStatus::Active)->create();
    GatewayTestHelpers::connection($tenant, state: static fn ($f) => $f->apiKey()->state(['charges_enabled' => false, 'payouts_enabled' => false]));
    [, $key] = ApiTestHelpers::key($tenant);

    createLink($key, ApiTestHelpers::body())->assertCreated();
});

it('answers gateway_not_ready for a live api_key connection without charges (ADR-0055)', function (): void {
    $tenant = Tenant::factory()->status(TenantStatus::Active)->create();
    GatewayTestHelpers::connection($tenant, livemode: true, state: static fn ($f) => $f->apiKey(GatewayTestHelpers::restrictedKey(true), GatewayTestHelpers::publishableKey(true))->state(['charges_enabled' => false]));
    [, $key] = ApiTestHelpers::key($tenant, livemode: true);

    expectApiError(createLink($key, ApiTestHelpers::body()), ApiErrorCode::GatewayNotReady);
});

it('requires a JSON object body', function (string $contentType, string $raw): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $headers = [...ApiTestHelpers::headers($key, 'idem-json'), 'Content-Type' => $contentType];

    expectApiError(
        ApiTestHelpers::raw('POST', apiUrl('v1/payment_links'), $headers, $raw),
        ApiErrorCode::ParameterInvalid,
    );
})->with([
    'form encoded' => ['text/plain', 'amount=10'],
    'a JSON list' => ['application/json', '[1,2]'],
    'broken JSON' => ['application/json', '{"amount": '],
]);

it('accepts metadata keys made of digits and returns them as an object', function (string $raw, array $expected): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $response = ApiTestHelpers::raw('POST', apiUrl('v1/payment_links'), [
        ...ApiTestHelpers::headers($key, 'digits-'.md5($raw)),
        'Content-Type' => 'application/json',
    ], '{"amount":"10.00","currency":"USD","description":"x","metadata":'.$raw.'}');

    $response->assertCreated();
    expect((string) $response->getContent())->toContain('"metadata":'.$raw);

    $id = $response->json('id');
    assert(is_string($id));

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$id))
        ->assertOk()
        ->assertJsonPath('metadata', $expected);
    expect((string) withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$id))->getContent())->toContain('"metadata":'.$raw);
})->with([
    'zero' => ['{"0":"a"}', ['a']],
    'numeric' => ['{"123":"order"}', [123 => 'order']],
    'mixed' => ['{"0":"a","1":"b","order_id":"A-1"}', ['a', 'b', 'order_id' => 'A-1']],
]);

it('rejects metadata sent as a JSON list', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    expectApiError(ApiTestHelpers::raw('POST', apiUrl('v1/payment_links'), [
        ...ApiTestHelpers::headers($key, 'list-metadata'),
        'Content-Type' => 'application/json',
    ], '{"amount":"10.00","currency":"USD","description":"x","metadata":["a"]}'), ApiErrorCode::MetadataInvalid, 'metadata');
});

it('rejects impossible expiration dates instead of rolling them over', function (string $date): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    expectApiError(createLink($key, ApiTestHelpers::body(['expires_at' => $date])), ApiErrorCode::ParameterInvalid, 'expires_at');
})->with([
    'February 31' => ['2026-02-31T10:00Z'],
    'February 29 in a common year' => ['2027-02-29T10:00:00Z'],
    'month 13' => ['2026-13-01T10:00:00Z'],
    'hour 24' => ['2026-10-01T24:00:00Z'],
    'minute 60' => ['2026-10-01T10:60:00Z'],
    'second 60' => ['2026-10-01T10:00:60Z'],
    'offset +15:00' => ['2026-10-01T10:00:00+15:00'],
    'offset minutes 60' => ['2026-10-01T10:00:00+05:60'],
]);

it('refuses the link when the gateway is disconnected between the first check and the insert', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $disconnected = false;

    // Simulated interleaving: the disconnection lands right when the create
    // transaction begins, after the readiness pre-check passed.
    Event::listen(TransactionBeginning::class, static function () use ($tenant, &$disconnected): void {
        if ($disconnected) {
            return;
        }

        $disconnected = true;
        GatewayConnection::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['status' => ConnectionStatus::Disconnected->value]);
    });

    expectApiError(createLink($key, ApiTestHelpers::body()), ApiErrorCode::GatewayNotReady);
    expect($disconnected)->toBeTrue()
        ->and(PaymentLink::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('accepts the upper limits end to end', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    $tenant->forceFill(['allowed_return_domains' => ['shop.example.com']])->save();
    [, $key] = ApiTestHelpers::key($tenant);
    $metadata = [];

    foreach (range(1, 20) as $i) {
        $metadata[str_pad("k{$i}", 40, 'x')] = str_repeat('v', 500);
    }

    $returnUrl = 'https://shop.example.com/'.str_repeat('a', 2048 - 25);

    createLink($key, ApiTestHelpers::body([
        'description' => '  '.str_repeat('d', 500).'  ',
        'metadata' => $metadata,
        'client_reference_id' => str_repeat('r', 200),
        'expires_at' => '2026-12-25T12:00:00Z',
        'return_url' => $returnUrl,
    ]), str_pad('key_with.dots:', 255, 'x'))
        ->assertCreated()
        ->assertJsonPath('description', str_repeat('d', 500))
        ->assertJsonCount(20, 'metadata')
        ->assertJsonPath('expires_at', '2026-12-25T12:00:00Z')
        ->assertJsonPath('return_url', $returnUrl);

    createLink($key, ApiTestHelpers::body(['expires_in_hours' => 2160]))->assertCreated()->assertJsonPath('expires_at', '2026-12-25T12:00:00Z');
});

it('checks the tenant before the gateway (ADR-0048 §6)', function (): void {
    $tenant = Tenant::factory()->status(TenantStatus::Suspended)->create();
    [, $key] = ApiTestHelpers::key($tenant);

    expectApiError(createLink($key, ApiTestHelpers::body()), ApiErrorCode::TenantSuspended);
});

it('reports the first of two invalid fields (ADR-0048 §6)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    expectApiError(createLink($key, ['amount' => '1,00', 'currency' => 'USD', 'description' => str_repeat('x', 501)]), ApiErrorCode::AmountInvalid, 'amount');
    expectApiError(createLink($key, ApiTestHelpers::body(['description' => '', 'expires_in_hours' => 'soon'])), ApiErrorCode::ParameterMissing, 'description');
});
