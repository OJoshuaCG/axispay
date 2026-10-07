<?php

declare(strict_types=1);

use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PayerDetails;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakeHostResolver;
use Tests\Support\ValidationTestHelpers as Validation;

use function Pest\Laravel\get;

/*
 * A link with every payer field `hidden` (spec B7, ADR-0064; the pbx hosted
 * checkout already knows the payer): the page asks the payer for nothing
 * but the card, the payment completes, no e-mail or name is collected or
 * stored, and the pre-payment validation body carries no payer data.
 */

/**
 * @return array<string, string>
 */
function allHidden(): array
{
    $config = [];

    foreach (PayerField::cases() as $field) {
        $config[$field->value] = PayerFieldRequirement::Hidden->value;
    }

    return $config;
}

/**
 * @return array{0: Tenant, 1: PaymentLink}
 */
function hiddenPayerScenario(): array
{
    FakeHostResolver::install();
    [$tenant, $link] = Checkout::scenario(static fn ($factory) => $factory->state(['payer_fields_config' => allHidden(), 'pre_payment_validation' => true]));
    Validation::endpoint($tenant);
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    Notification::fake();

    return [$tenant, $link];
}

/**
 * @return list<PayerDetails>
 */
function storedPayerData(PaymentLink $link): array
{
    return Checkout::inTenant($link, static fn (): array => array_values(PayerDetails::query()->get()->filter(static fn (PayerDetails $details): bool => ($details->data ?? []) !== [])->all()));
}

it('renders the page without any payer field', function (): void {
    [, $link] = hiddenPayerScenario();

    $response = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk();

    $response->assertSee('data-pay-button', false)
        ->assertDontSee('payer[', false)
        ->assertDontSee('data-payer', false);
});

it('completes the payment with no payer data sent', function (): void {
    [, $link] = hiddenPayerScenario();

    Checkout::pay($link, body: ['payer' => []])->assertOk()->assertJson(['outcome' => 'paid']);

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(storedPayerData($link))->toBe([]);
});

it('completes the payment with the payer key missing altogether', function (): void {
    [, $link] = hiddenPayerScenario();

    $response = \Pest\Laravel\postJson(payUrl('/l/'.$link->public_token.'/attempts'), ['confirmation_token' => 'ctoken_success'], ['User-Agent' => 'Mozilla/5.0 (Test)']);

    $response->assertOk()->assertJson(['outcome' => 'paid']);
    expect(storedPayerData($link))->toBe([]);
});

it('ignores payer data sent anyway for hidden fields: nothing is stored and the validation body has no payer', function (): void {
    [, $link] = hiddenPayerScenario();

    Checkout::pay($link, body: ['payer' => ['email' => 'ana@example.com', 'full_name' => 'Ana Pérez', 'phone' => '5512345678']])
        ->assertOk()
        ->assertJson(['outcome' => 'paid']);

    [$request] = Validation::sentRequests();
    $payload = jsonArray($request->body());

    expect(storedPayerData($link))->toBe([])
        ->and(data_get($payload, 'data.payer'))->toBeNull()
        ->and($request->body())->not->toContain('ana@example.com')
        ->and($request->body())->not->toContain('Ana Pérez');
});

it('sends no payer block to the validation URL when no payer data was collected', function (): void {
    [, $link] = hiddenPayerScenario();

    Checkout::pay($link, body: ['payer' => []])->assertOk();

    [$request] = Validation::sentRequests();

    expect(jsonArray($request->body())['data'])->toHaveKey('payer')
        ->and(data_get(jsonArray($request->body()), 'data.payer'))->toBeNull();
});
