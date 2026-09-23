<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiErrorType;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Http\Errors\ApiSurface;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\AmountParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Plan section 10.4: every API error uses the same envelope, with the
 * request id, and only on the API host.
 */
beforeEach(function (): void {
    Route::domain(ApiSurface::host())->middleware('api')->prefix('v1/_test')->group(function (): void {
        Route::get('api-exception', fn () => throw ApiException::of(ApiErrorCode::TenantSuspended));
        Route::post('validation', fn (Request $request) => $request->validate([
            'description' => ['required', 'string'],
            'client_reference_id' => ['nullable', 'string', 'max:5'],
        ]));
        Route::post('amount', fn (Request $request) => app(AmountParser::class)
            ->parse($request->json('amount'), $request->json('currency'))
            ->toApiArray());
        Route::get('boom', fn () => throw new RuntimeException('Database password hunter2 and sk_live_abc leaked'));
        Route::get('links/{id}', fn (string $id) => ['ulid' => PrefixedId::decode($id, ResourceType::PaymentLink)]);
        Route::get('throttled', fn () => ['ok' => true])->middleware('throttle:1,1');
    });
});

/**
 * @param  TestResponse<Response>  $response
 * @return array<array-key, mixed>
 */
function assertApiError(TestResponse $response, int $status, ApiErrorCode $code, ?string $param = null): array
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['error' => ['type', 'code', 'message', 'param', 'request_id']])
        ->assertJsonPath('error.type', $code->type()->value)
        ->assertJsonPath('error.code', $code->value)
        ->assertJsonPath('error.param', $param)
        ->assertJsonPath('error.request_id', $response->headers->get('Request-Id'));

    $error = $response->json('error');

    return is_array($error) ? $error : [];
}

it('renders unknown API routes as resource_not_found', function (): void {
    assertApiError(getJson(apiUrl('v1/nope')), 404, ApiErrorCode::ResourceNotFound);
});

it('renders unknown API routes as JSON even without an Accept header', function (): void {
    assertApiError(get(apiUrl('v1/nope')), 404, ApiErrorCode::ResourceNotFound);
});

it('renders an ApiException with its own code, type and status', function (): void {
    $error = assertApiError(getJson(apiUrl('v1/_test/api-exception')), 403, ApiErrorCode::TenantSuspended);

    expect($error['type'])->toBe(ApiErrorType::Permission->value);
});

it('maps a missing required field to parameter_missing with the param', function (): void {
    assertApiError(
        postJson(apiUrl('v1/_test/validation'), []),
        400,
        ApiErrorCode::ParameterMissing,
        'description',
    );
});

it('maps an invalid field to parameter_invalid with the param', function (): void {
    assertApiError(
        postJson(apiUrl('v1/_test/validation'), ['description' => 'Order', 'client_reference_id' => 'too-long']),
        400,
        ApiErrorCode::ParameterInvalid,
        'client_reference_id',
    );
});

it('rejects a JSON number amount with amount_must_be_string (critical case 14)', function (): void {
    assertApiError(
        postJson(apiUrl('v1/_test/amount'), ['amount' => 150.5, 'currency' => 'USD']),
        400,
        ApiErrorCode::AmountMustBeString,
        'amount',
    );
});

it('accepts a string amount and answers with the money representation', function (): void {
    postJson(apiUrl('v1/_test/amount'), ['amount' => '150.50', 'currency' => 'mxn'])
        ->assertOk()
        ->assertExactJson(['amount' => '150.50', 'amount_minor' => 15050, 'currency' => 'MXN']);
});

it('rejects an unsupported currency with currency_not_supported', function (): void {
    $error = assertApiError(
        postJson(apiUrl('v1/_test/amount'), ['amount' => '10.00', 'currency' => 'EUR']),
        400,
        ApiErrorCode::CurrencyNotSupported,
        'currency',
    );

    expect($error['message'])->toBe("The currency 'EUR' is not supported. Allowed currencies: USD, MXN.");
});

it('treats a wrongly prefixed ID as resource_not_found', function (): void {
    assertApiError(
        getJson(apiUrl('v1/_test/links/pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9S')),
        404,
        ApiErrorCode::ResourceNotFound,
    );

    getJson(apiUrl('v1/_test/links/plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S'))
        ->assertOk()
        ->assertExactJson(['ulid' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9S']);
});

it('hides internal errors behind a generic internal_error', function (): void {
    $response = getJson(apiUrl('v1/_test/boom'));

    $error = assertApiError($response, 500, ApiErrorCode::InternalError);

    expect($error['message'])->toBe(ApiErrorCode::InternalError->defaultMessage())
        ->and($response->getContent())->not->toContain('hunter2')
        ->and($response->getContent())->not->toContain('sk_live_abc');
});

it('renders a wrong HTTP method as method_not_allowed', function (): void {
    assertApiError(postJson(apiUrl('v1/_test/api-exception')), 405, ApiErrorCode::MethodNotAllowed);
});

it('renders throttling as rate_limited and keeps Retry-After', function (): void {
    getJson(apiUrl('v1/_test/throttled'))->assertOk();

    $response = getJson(apiUrl('v1/_test/throttled'));

    assertApiError($response, 429, ApiErrorCode::RateLimited);
    $response->assertHeader('Retry-After');
});

it('leaves web errors with the default rendering', function (): void {
    $response = get('/definitely-missing');

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($response->getContent())->not->toContain('"error":{"type"');
});

it('keeps Laravel JSON errors for JSON requests outside the API host', function (): void {
    getJson('/definitely-missing')
        ->assertNotFound()
        ->assertJsonMissingPath('error.code')
        ->assertJsonStructure(['message']);
});

it('assigns every error code a status that matches its type', function (ApiErrorCode $code): void {
    $expected = match (true) {
        $code->httpStatus() === 401 => ApiErrorType::Authentication,
        $code->httpStatus() === 403 => ApiErrorType::Permission,
        $code->httpStatus() === 429 => ApiErrorType::RateLimit,
        $code->httpStatus() >= 500 => ApiErrorType::Api,
        default => ApiErrorType::InvalidRequest,
    };

    expect($code->type())->toBe($expected)
        ->and($code->defaultMessage())->not->toBeEmpty();
})->with(ApiErrorCode::cases());
