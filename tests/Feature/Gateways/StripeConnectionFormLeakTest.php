<?php

declare(strict_types=1);

use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Models\GatewayConnection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\MessageBag;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeApiKeyScenario;

use function Pest\Laravel\startSession;

/**
 * Plan 26.2 case 19 on the panel form: Filament validates the action form
 * BEFORE the action callback runs (unchecked notice, missing password, too
 * long). Whatever fails, the typed restricted key never comes back in the
 * Livewire response: not in the HTML, the snapshot, the effects, the error
 * bag or the logs.
 */
beforeEach(function (): void {
    startSession();
    Notification::fake();
});

/**
 * @param  Testable<Component>  $component
 */
function expectNoKeyInResponse(Testable $component, string $secret, TestHandler $logs): void
{
    $body = substr($secret, strlen('rk_test_'));
    $errors = $component->errors();
    $places = [
        'html' => $component->html(),
        'snapshot' => (string) json_encode($component->__get('snapshot')),
        'effects' => (string) json_encode($component->__get('effects')),
        'errors' => (string) json_encode($errors instanceof MessageBag ? $errors->toArray() : []),
        'logs' => (string) json_encode(array_map(static fn ($record): array => $record->toArray(), $logs->getRecords())),
    ];

    foreach ($places as $where => $text) {
        // Not toContain(): it is variadic, a message would become a second needle.
        expect(str_contains($text, $body))->toBeFalse("The restricted key came back in the {$where}.");
    }
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<array-key, string>  $errors
 */
it('never returns the typed key when the form fails validation or the key is refused', function (array $data, array $errors, bool $reauthenticated): void {
    $logs = captureDefaultLog();
    actingAsTenantUser(tenantUser());

    if ($reauthenticated) {
        GatewayTestHelpers::reauthenticated();
    }

    (new StripeApiKeyScenario(stripeHttp()))->install();
    $secret = is_string($data['restricted_key'] ?? null) ? $data['restricted_key'] : '';

    $component = submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', [
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => true,
        ...$data,
    ])
        ->assertHasActionErrors($errors);

    expectNoKeyInResponse($component, $secret, $logs);
    expect(GatewayConnection::query()->count())->toBe(0);
})->with([
    'risk notice not accepted' => [['restricted_key' => 'rk_test_51LeakNoticeNotAccepted000000Xy01', 'risk_acknowledged' => false], ['risk_acknowledged'], true],
    'password missing (window closed)' => [['restricted_key' => 'rk_test_51LeakPasswordMissing0000000Xy02'], ['current_password' => 'required'], false],
    'longer than allowed' => [['restricted_key' => 'rk_test_51LeakTooLong'.str_repeat('Q', 260)], ['restricted_key'], true],
    'invalid prefix' => [['restricted_key' => 'xx_test_51LeakInvalidPrefix000000000Xy04'], ['restricted_key'], true],
    'secret key' => [['restricted_key' => 'sk_test_51LeakSecretKey0000000000000Xy05'], ['restricted_key'], true],
]);

it('never returns the typed key after a successful connection either', function (): void {
    $logs = captureDefaultLog();
    actingAsTenantUser(tenantUser());
    GatewayTestHelpers::reauthenticated();
    (new StripeApiKeyScenario(stripeHttp()))->install();
    $secret = GatewayTestHelpers::restrictedKey(suffix: 'Ok06');

    $component = submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', [
        'restricted_key' => $secret,
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => true,
    ])
        ->assertHasNoActionErrors();

    expectNoKeyInResponse($component, $secret, $logs);
});

it('clears the key on the update-keys form too', function (): void {
    $logs = captureDefaultLog();
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->apiKey());
    $secret = 'rk_test_51LeakUpdateForm000000000000Xy07';

    $component = submitAction(Livewire::test(StripeConnection::class), 'updateKeys', [
        'restricted_key' => $secret,
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => false,
    ])
        ->assertHasActionErrors(['risk_acknowledged']);

    expectNoKeyInResponse($component, $secret, $logs);
});
