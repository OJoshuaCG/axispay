<?php

declare(strict_types=1);

use App\Modules\Checkout\Models\ReturnSigningSecret;
use App\Modules\Checkout\Services\ReturnSigningSecrets;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\flushSession;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
 * Return to the merchant (spec B7, ADR-0064): after a successful payment the
 * page can send the payer back to the link's `return_url` by itself (a short
 * countdown, per link), and every return carries a signed proof the merchant
 * verifies with its per-tenant secret. The checkout token and gateway data
 * never travel in the URL. The expired and canceled pages offer the way back
 * too, without any proof.
 *
 * The proof format, as documented for merchants:
 *   ref (the link's client_reference_id, when it has one), plink, payment,
 *   status=paid, ts (Unix seconds) and sig: the hex HMAC-SHA256 of
 *   "plink\npayment\nstatus\nts\nref" keyed with the secret; during a rotation
 *   sig holds one signature per active secret, separated by a comma.
 */

const RETURN_URL = 'https://shop.example.com/checkout/return?order=A-1029';

/**
 * @param  array<string, mixed>  $link
 * @return array{0: Tenant, 1: PaymentLink}
 */
function returnScenario(array $link = []): array
{
    [$tenant, $paymentLink] = Checkout::scenario(static fn ($factory) => $factory->state([
        'currency' => CurrencyCode::MXN,
        'amount_minor' => 50_000,
        'return_url' => RETURN_URL,
        'auto_redirect' => true,
        'client_reference_id' => 'A-1029',
        ...$link,
    ]));

    return [$tenant, $paymentLink];
}

/**
 * @return TestResponse<Response>
 */
function completePage(PaymentLink $link): TestResponse
{
    return get(payUrl('/l/'.$link->public_token.'/complete'), ['User-Agent' => 'Mozilla/5.0']);
}

/**
 * @return TestResponse<Response>
 */
function paidPage(PaymentLink $link): TestResponse
{
    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    return completePage($link);
}

/**
 * @param  TestResponse<Response>  $response
 */
function attributeOf(TestResponse $response, string $attribute): ?string
{
    preg_match('/\b'.preg_quote($attribute, '/').'="([^"]*)"/', (string) $response->getContent(), $match);

    return isset($match[1]) ? html_entity_decode($match[1]) : null;
}

/**
 * @return array<string, string>
 */
function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $typed = [];

    foreach ($query as $name => $value) {
        $typed[(string) $name] = is_string($value) ? $value : '';
    }

    return $typed;
}

/**
 * The signatures a merchant would accept for the proof in `$url`, one per secret.
 *
 * @param  list<string>  $secrets
 */
function signatureMatches(string $url, array $secrets): bool
{
    $query = queryOf($url);
    $message = implode("\n", [$query['plink'] ?? '', $query['payment'] ?? '', $query['status'] ?? '', $query['ts'] ?? '', $query['ref'] ?? '']);
    $given = explode(',', $query['sig'] ?? '');

    foreach ($secrets as $secret) {
        if (! in_array(hash_hmac('sha256', $message, $secret), $given, true)) {
            return false;
        }
    }

    return count($given) === count($secrets);
}

/**
 * @return list<string>
 */
function secretsOf(PaymentLink $link): array
{
    return Checkout::inTenant($link, static fn (): array => app(ReturnSigningSecrets::class)->signingSecrets());
}

beforeEach(function (): void {
    travelTo('2026-10-09 18:00:00');
    config(['axispay.checkout.turnstile_after_failures' => 99]);
});

it('redirects by itself, after a countdown, the session that paid, with a proof the merchant can verify', function (): void {
    [, $link] = returnScenario();

    $response = paidPage($link)->assertOk();
    $url = attributeOf($response, 'data-redirect-url');
    $attempt = Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::query()->where('payment_link_id', $link->id)->sole());

    expect(attributeOf($response, 'data-auto-redirect'))->toBe((string) config()->integer('axispay.checkout.redirect_countdown_seconds'))
        ->and($url)->toStartWith('https://shop.example.com/checkout/return?order=A-1029&');

    $query = queryOf((string) $url);

    expect($query)->toMatchArray([
        'order' => 'A-1029',
        'ref' => 'A-1029',
        'plink' => $link->prefixedId(),
        'payment' => $attempt->prefixedId(),
        'status' => 'paid',
        'ts' => (string) now()->getTimestamp(),
    ])->and(array_keys($query))->toBe(['order', 'ref', 'plink', 'payment', 'status', 'ts', 'sig'])
        ->and($query['sig'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(signatureMatches((string) $url, secretsOf($link)))->toBeTrue();

    $response->assertSee('data-redirect-countdown', false)
        ->assertSee('data-redirect-stop', false);
});

it('never puts the checkout token or any gateway data in the return URL', function (): void {
    [, $link] = returnScenario();
    $url = (string) attributeOf(paidPage($link), 'data-redirect-url');
    $attempt = Checkout::attempts($link)[0];

    expect($url)->not->toContain($link->public_token)
        ->and($url)->not->toContain((string) $attempt->provider_payment_id)
        ->and($url)->not->toContain('pi_')
        ->and($url)->not->toContain('secret')
        ->and($url)->not->toContain('acct_');
});

it('offers the same signed return as a button when the link does not redirect by itself', function (): void {
    [, $link] = returnScenario(['auto_redirect' => false]);

    $response = paidPage($link)->assertOk();

    expect(attributeOf($response, 'data-auto-redirect'))->toBeNull()
        ->and(attributeOf($response, 'data-redirect-url'))->toBeNull();
    $response->assertSee('Volver a Tienda Demo')->assertSee('rel="noopener noreferrer"', false);

    $found = preg_match('/<a\b[^>]*\bhref="([^"]*sig=[^"]*)"/s', (string) $response->getContent(), $match) === 1 ? html_entity_decode($match[1]) : null;

    expect($found)->not->toBeNull()
        ->and(signatureMatches((string) $found, secretsOf($link)))->toBeTrue();
});

it('does not redirect on its own a payer who comes back to an already paid link, but still offers the way back', function (): void {
    [, $link] = returnScenario();
    paidPage($link)->assertOk();
    flushSession();

    $response = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk();

    expect(attributeOf($response, 'data-auto-redirect'))->toBeNull();
    $response->assertSee('Este cobro ya fue pagado')->assertSee('Volver a Tienda Demo');
});

it('has no return action and no redirect without a return_url', function (): void {
    [, $link] = returnScenario(['return_url' => null, 'auto_redirect' => false]);

    $response = paidPage($link)->assertOk();

    expect(attributeOf($response, 'data-auto-redirect'))->toBeNull();
    $response->assertDontSee('Volver a Tienda Demo')->assertDontSee('data-redirect-countdown', false);
});

it('keeps the merchant\'s query string and fragment and appends the proof before the fragment', function (): void {
    [, $link] = returnScenario(['return_url' => 'https://shop.example.com/back#done']);

    $url = (string) attributeOf(paidPage($link), 'data-redirect-url');

    expect($url)->toStartWith('https://shop.example.com/back?ref=A-1029&plink=')
        ->and($url)->toEndWith('#done')
        ->and(signatureMatches(str_replace('#done', '', $url), secretsOf($link)))->toBeTrue();
});

it('leaves ref out of the proof when the link has no client reference', function (): void {
    [, $link] = returnScenario(['client_reference_id' => null]);

    $url = (string) attributeOf(paidPage($link), 'data-redirect-url');

    expect(queryOf($url))->not->toHaveKey('ref')
        ->and(signatureMatches($url, secretsOf($link)))->toBeTrue();
});

it('creates the tenant secret once per mode and keeps it encrypted at rest', function (): void {
    [$tenant, $link] = returnScenario();
    paidPage($link);
    [, $second] = returnScenario();
    $secrets = secretsOf($link);

    expect($secrets)->toHaveCount(1)
        ->and($secrets[0])->toStartWith('rsec_')
        ->and(ReturnSigningSecret::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

    $stored = ReturnSigningSecret::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail()->getRawOriginal('secret');
    expect(is_string($stored) ? $stored : '')->not->toContain($secrets[0])
        ->and(secretsOf($second))->not->toBe($secrets);
});

it('signs with both secrets while a rotation overlaps, and with the new one alone afterwards', function (): void {
    [, $link] = returnScenario();
    $old = secretsOf($link)[0];

    $new = Checkout::inTenant($link, static fn (): string => app(ReturnSigningSecrets::class)->rotate());
    $url = (string) attributeOf(paidPage($link), 'data-redirect-url');

    expect($new)->not->toBe($old)
        ->and(signatureMatches($url, [$new, $old]))->toBeTrue();

    travelTo(now()->addHours(25));
    expect(secretsOf($link))->toBe([$new]);
});

it('adds the proof to the return_url of the status the page polls', function (): void {
    [, $link] = returnScenario();
    Checkout::pay($link)->assertOk();

    $polled = Checkout::status($link)->assertOk()->json('return_url');
    $url = is_string($polled) ? $polled : '';

    expect($url)->toStartWith('https://shop.example.com/checkout/return?order=A-1029&')
        ->and(signatureMatches($url, secretsOf($link)))->toBeTrue();
});

it('offers a return action, without any proof, on the expired and canceled pages', function (string $state): void {
    [, $link] = returnScenario();
    [, $closed] = Checkout::scenario(static fn (PaymentLinkFactory $factory): PaymentLinkFactory => ($state === 'expired' ? $factory->expired() : $factory->canceled())->state(['return_url' => RETURN_URL, 'auto_redirect' => true, 'client_reference_id' => 'A-1029']));

    $response = get(payUrl('/l/'.$closed->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk();
    $content = (string) $response->getContent();

    $response->assertSee('Volver a Tienda Demo');
    expect($content)->toContain('href="'.RETURN_URL.'"')
        ->and($content)->not->toContain('sig=')
        ->and($content)->not->toContain('data-auto-redirect')
        ->and($link->id)->not->toBe($closed->id);
})->with(['expired' => ['expired'], 'canceled' => ['canceled']]);

it('shows no return action while the link can still be paid', function (): void {
    [, $link] = returnScenario();

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertDontSee('Volver a Tienda Demo');
});

it('accepts auto_redirect on the API only with a return_url', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $tenant->forceFill(['allowed_return_domains' => ['shop.example.com']])->save();
    $headers = static fn (): array => ApiTestHelpers::headers($key, 'idem-'.bin2hex(random_bytes(6)));

    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['return_url' => RETURN_URL, 'auto_redirect' => true]), $headers())
        ->assertCreated()
        ->assertJsonPath('auto_redirect', true);
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['return_url' => RETURN_URL]), $headers())
        ->assertCreated()
        ->assertJsonPath('auto_redirect', false);
    postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(), $headers())
        ->assertCreated()
        ->assertJsonPath('auto_redirect', false);

    foreach ([['auto_redirect' => true], ['return_url' => RETURN_URL, 'auto_redirect' => 'yes']] as $body) {
        postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body($body), $headers())
            ->assertStatus(ApiErrorCode::ParameterInvalid->httpStatus())
            ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
            ->assertJsonPath('error.param', 'auto_redirect');
    }
});
