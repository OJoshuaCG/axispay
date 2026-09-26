<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Money\CurrencyCode;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * GET /v1/payment_links/{id} and GET /v1/payment_links (plan 10.1, 10.5).
 */

/**
 * @return TestResponse<Response>
 */
function listLinks(string $key, string $query = ''): TestResponse
{
    return withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links'.($query !== '' ? '?'.$query : '')));
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function listedIds(TestResponse $response): array
{
    return ApiTestHelpers::listed($response);
}

it('retrieves a link by its prefixed ID', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['metadata' => ['order' => 'A-1']]));

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$link->prefixedId()))
        ->assertOk()
        ->assertJsonPath('id', $link->prefixedId())
        ->assertJsonPath('amount', '1500.00')
        ->assertJsonPath('metadata.order', 'A-1')
        ->assertJsonPath('url', 'https://pay.localhost/l/'.$link->public_token);
});

it('builds the public URL from the configured base', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    config(['axispay.links.public_base_url' => 'http://pay.localhost:8000/']);
    $link = ApiTestHelpers::link($tenant);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$link->prefixedId()))
        ->assertJsonPath('url', 'http://pay.localhost:8000/l/'.$link->public_token);
});

it('answers 404 for a wrong prefix, a bare ULID or an unknown ID', function (string $id): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payment_links/'.$id))
        ->assertNotFound()
        ->assertJsonPath('error.code', ApiErrorCode::ResourceNotFound->value);
})->with([
    'pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    '01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    'plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    'plink_01j8z3q6t4y0v8kx2m1n5p7r9s',
]);

it('lists links newest first with has_more', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-26 10:00:00');
    $links = [];

    foreach (range(1, 3) as $i) {
        Carbon::setTestNow(now()->addSecond());
        $links[] = ApiTestHelpers::link($tenant);
    }

    $response = listLinks($key, 'limit=2')->assertOk()->assertJsonPath('object', 'list')->assertJsonPath('has_more', true);
    expect(listedIds($response))->toBe([$links[2]->prefixedId(), $links[1]->prefixedId()]);

    $next = listLinks($key, 'limit=2&starting_after='.$links[1]->prefixedId())->assertJsonPath('has_more', false);
    expect(listedIds($next))->toBe([$links[0]->prefixedId()]);

    $previous = listLinks($key, 'limit=1&ending_before='.$links[0]->prefixedId())->assertJsonPath('has_more', true);
    expect(listedIds($previous))->toBe([$links[1]->prefixedId()]);

    $top = listLinks($key, 'limit=5&ending_before='.$links[0]->prefixedId())->assertJsonPath('has_more', false);
    expect(listedIds($top))->toBe([$links[2]->prefixedId(), $links[1]->prefixedId()]);
});

it('returns 20 links by default and at most 100', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    foreach (range(1, 21) as $i) {
        ApiTestHelpers::link($tenant);
    }

    listLinks($key)->assertJsonCount(20, 'data')->assertJsonPath('has_more', true);
    listLinks($key, 'limit=100')->assertJsonCount(21, 'data')->assertJsonPath('has_more', false);
});

it('filters by status, currency, reference and creation date', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    Carbon::setTestNow('2026-09-20 12:00:00');
    $old = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['client_reference_id' => 'REF-1']));
    Carbon::setTestNow('2026-09-25 12:00:00');
    $paid = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->paid()->state(['currency' => CurrencyCode::MXN]));
    $canceled = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->canceled()->state(['client_reference_id' => 'REF-2']));

    expect(listedIds(listLinks($key, 'status=paid')))->toBe([$paid->prefixedId()])
        ->and(listedIds(listLinks($key, 'currency=mxn')))->toBe([$paid->prefixedId()])
        ->and(listedIds(listLinks($key, 'client_reference_id=REF-2')))->toBe([$canceled->prefixedId()])
        ->and(listedIds(listLinks($key, 'created[lte]=2026-09-21T00:00:00Z')))->toBe([$old->prefixedId()])
        ->and(listedIds(listLinks($key, 'created[gte]='.Carbon::parse('2026-09-24 00:00:00')->getTimestamp())))->toBe([$canceled->prefixedId(), $paid->prefixedId()])
        ->and(listedIds(listLinks($key, 'status=active&currency=USD')))->toBe([$old->prefixedId()]);
});

it('rejects invalid list parameters', function (string $query, string $param): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    listLinks($key, $query)
        ->assertStatus(400)
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', $param);
})->with([
    ['limit=0', 'limit'],
    ['limit=101', 'limit'],
    ['limit=abc', 'limit'],
    ['status=open', 'status'],
    ['currency=EUR', 'currency'],
    ['starting_after=pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9S', 'starting_after'],
    ['starting_after=plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S&ending_before=plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9T', 'ending_before'],
    ['created[gte]=yesterday', 'created[gte]'],
    ['created[gte]=2026-02-31T00:00:00Z', 'created[gte]'],
    ['created[lte]=2026-09-26T25:00:00Z', 'created[lte]'],
    ['created=5', 'created'],
    ['client_reference_id='.str_repeat('x', 201), 'client_reference_id'],
]);

it('never lists another tenant\'s links', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    $other = ApiTestHelpers::readyTenant();
    ApiTestHelpers::link($other);
    $own = ApiTestHelpers::link($tenant);

    expect(listedIds(listLinks($key)))->toBe([$own->prefixedId()]);
    expect(PaymentLink::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('shows every status in the list', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    foreach ([PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled, PaymentLinkStatus::Paid, PaymentLinkStatus::Processing] as $state) {
        ApiTestHelpers::link($tenant, state: ApiTestHelpers::inStatus($state));
    }

    $statuses = ApiTestHelpers::listed(listLinks($key), 'status');
    sort($statuses);

    expect($statuses)->toBe(array_map(static fn (PaymentLinkStatus $s): string => $s->value, [PaymentLinkStatus::Canceled, PaymentLinkStatus::Expired, PaymentLinkStatus::Paid, PaymentLinkStatus::Processing]));
});
