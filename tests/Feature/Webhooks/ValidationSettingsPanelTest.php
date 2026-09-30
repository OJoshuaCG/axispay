<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\ValidationFinalDecision;
use App\Modules\Webhooks\Enums\ValidationResponseProblem;
use App\Modules\Webhooks\Filament\Pages\PrePaymentValidationSettings;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;
use Tests\Support\FakeHostResolver;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\ValidationTestHelpers as Validation;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/**
 * Settings → Pre-payment validation in the tenant panel (plan 15.8.1,
 * 15.8.5, 15.8.7): `webhooks:manage`, the secret shown once, "Test
 * validation", the failing alert, the recent calls, and the calls on the
 * link's detail. The actions themselves: ValidationEndpointActionsTest.
 */
beforeEach(function (): void {
    startSession();
    FakeHostResolver::install();
    Notification::fake();
});

/**
 * A logged validation call of a link, written directly.
 *
 * @param  array<string, mixed>  $attributes
 */
function panelValidationCall(Tenant $tenant, ?PaymentLink $link, array $attributes = []): ValidationCall
{
    return Validation::in($tenant, false, static function () use ($link, $attributes): ValidationCall {
        $call = new ValidationCall;
        $call->forceFill([
            'livemode' => false,
            'payment_link_id' => $link?->id,
            'attempt_number' => 1,
            'is_test' => false,
            'request_payload' => ['type' => 'payment.pre_validation'],
            'outcome' => ValidationCallOutcome::Approved,
            'final_decision' => ValidationFinalDecision::Charge,
            'response_status' => 200,
            'duration_ms' => 180,
            ...$attributes,
        ])->save();

        return $call;
    });
}

it('is only reachable with webhooks:manage', function (): void {
    $tenant = activeTenant();

    foreach ([SystemRole::Viewer, SystemRole::Finance, SystemRole::LinkCreator] as $role) {
        actingAsTenantUser(tenantUser($tenant, [$role]));
        get(appUrl('/settings/pre-payment-validation'))->assertForbidden();
        expect(PrePaymentValidationSettings::canAccess())->toBeFalse();
    }

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    get(appUrl('/settings/pre-payment-validation'))->assertOk()->assertSee(__('webhooks.validation.page.title'), false);
});

it('shows the not-configured state and both failure policies explained', function (string $locale): void {
    $tenant = activeTenant();
    app()->setLocale($locale);
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(PrePaymentValidationSettings::class)
        ->assertSee(__('webhooks.validation.page.not_configured'))
        ->assertActionVisible('configure')
        ->assertActionHidden('testValidation')
        ->assertActionHidden('remove')
        ->mountAction('configure');
    $component->assertMountedActionModalSee(ValidationFailurePolicy::FailClosed->explanation());
    $component->assertMountedActionModalSee(ValidationFailurePolicy::FailOpen->explanation());
})->with(['en', 'es']);

/*
 * Regression: the page has a table (HasTable), so Filament's page layout does
 * not render the action modals and leaves them to the table view, but the
 * table is hidden until validation is configured or has calls. The modal of
 * "Configure" then had no container in the browser and nothing opened. The
 * page's own view renders exactly one container, whether the table shows or not.
 */
it('renders one action modal container, so "Configure" opens before anything exists', function (bool $configured): void {
    $tenant = activeTenant();

    if ($configured) {
        Validation::endpoint($tenant);
    }

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(PrePaymentValidationSettings::class);
    expect(substr_count($component->html(), 'wire:partial="action-modals"'))->toBe(1);

    $component->mountAction('configure')
        ->assertActionMounted('configure')
        ->assertFormFieldExists('url')
        ->assertFormFieldExists('failure_policy')
        ->assertFormFieldExists('enabled_by_default');
    $component->assertMountedActionModalSee($configured ? __('webhooks.validation.actions.edit') : __('webhooks.validation.actions.configure'));
})->with(['not configured, no calls' => false, 'configured' => true]);

it('configures the URL and shows its secret once, never in the page state', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(PrePaymentValidationSettings::class);
    $component->callAction('configure', data: ['url' => Validation::URL, 'failure_policy' => 'fail_open', 'enabled_by_default' => true]);
    $component->assertHasNoActionErrors()->assertActionMounted('showIssuedSecret');

    $endpoint = Validation::in($tenant, false, static fn (): ValidationEndpoint => ValidationEndpoint::query()->sole());
    expect($endpoint->failure_policy)->toBe(ValidationFailurePolicy::FailOpen)
        ->and($endpoint->enabled_by_default)->toBeTrue();
    $component->assertMountedActionModalSee($endpoint->secret);
    expect((string) json_encode($component->__get('snapshot')))->not->toContain($endpoint->secret);

    $component->callMountedAction();
    expect($component->html())->not->toContain($endpoint->secret);

    // Editing keeps the secret and does not show it again.
    Livewire::test(PrePaymentValidationSettings::class)
        ->callAction('configure', data: ['url' => Validation::URL, 'failure_policy' => 'fail_closed', 'enabled_by_default' => false])
        ->assertHasNoActionErrors()
        ->assertActionNotMounted('showIssuedSecret');
    expect(Validation::freshEndpoint($endpoint)->secret)->toBe($endpoint->secret)
        ->and(Validation::freshEndpoint($endpoint)->failure_policy)->toBe(ValidationFailurePolicy::FailClosed);
});

it('asks for the password again before configuring', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(PrePaymentValidationSettings::class)
        ->callAction('configure', data: ['url' => Validation::URL, 'failure_policy' => 'fail_closed', 'current_password' => 'wrong-password'])
        ->assertHasActionErrors(['current_password']);

    expect(Validation::in($tenant, false, static fn (): int => ValidationEndpoint::query()->count()))->toBe(0);
});

it('tests the validation and states the decision, with status, latency and warnings', function (): void {
    $tenant = activeTenant();
    Validation::endpoint($tenant);
    // A string body: an array body makes the fake force application/json.
    Http::fake(['*' => Http::response('{"decision":"approve"}', 200, ['Content-Type' => 'text/plain'])]);
    actingAsTenantUser(tenantUser($tenant));

    $component = Livewire::test(PrePaymentValidationSettings::class)
        ->callAction('testValidation')
        ->assertHasNoActionErrors()
        ->assertActionMounted('showTestResult');
    $component->assertMountedActionModalSee(__('webhooks.test_result.validation_approved'));
    $component->assertMountedActionModalSee(__('webhooks.test_result.decision_approve'));
    $component->assertMountedActionModalSee('200');
    $component->assertMountedActionModalSee(ValidationResponseProblem::ContentTypeNotJson->message());

    Http::assertSentCount(1);
    expect(Validation::calls($tenant)[0]->is_test)->toBeTrue();
});

it('shows an invalid test answer with its errors', function (): void {
    $tenant = activeTenant();
    Validation::endpoint($tenant);
    Http::fake(['*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json'])]);
    actingAsTenantUser(tenantUser($tenant));

    $component = Livewire::test(PrePaymentValidationSettings::class)
        ->callAction('testValidation')
        ->assertActionMounted('showTestResult');
    $component->assertMountedActionModalSee(__('webhooks.test_result.validation_invalid'));
    $component->assertMountedActionModalSee(ValidationResponseProblem::DecisionMissing->message());
    $component->assertMountedActionModalSee(ValidationFailureKind::InvalidResponse->label());
});

it('states a rejection of the example payment in the test result', function (): void {
    $tenant = activeTenant();
    Validation::endpoint($tenant);
    Http::fake(['*' => Http::response(['decision' => 'reject'], 200, ['Content-Type' => 'application/json'])]);
    actingAsTenantUser(tenantUser($tenant));

    $component = Livewire::test(PrePaymentValidationSettings::class)
        ->callAction('testValidation')
        ->assertActionMounted('showTestResult');
    $component->assertMountedActionModalSee(__('webhooks.test_result.validation_rejected'));
    $component->assertMountedActionModalSee(__('webhooks.test_result.decision_reject'));
});

it('shows the failing alert after repeated failures', function (): void {
    $tenant = activeTenant();
    $endpoint = Validation::endpoint($tenant, attributes: ['consecutive_failures' => ValidationEndpoint::alertThreshold(), 'last_failure_at' => now()]);
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(PrePaymentValidationSettings::class)->assertSee(__('webhooks.validation.alert.heading'));

    Validation::in($tenant, false, static fn () => ValidationEndpoint::query()->whereKey($endpoint->id)->update(['consecutive_failures' => 0]));

    Livewire::test(PrePaymentValidationSettings::class)->assertDontSee(__('webhooks.validation.alert.heading'));
});

it('lists the recent calls of the current mode only', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    Validation::endpoint($tenant);
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['description' => 'Order A-1029']));
    $rejected = panelValidationCall($tenant, $link, ['outcome' => ValidationCallOutcome::Rejected, 'reason_code' => 'out_of_stock', 'final_decision' => ValidationFinalDecision::Block]);
    $live = Validation::in($tenant, true, static function (): ValidationCall {
        $call = new ValidationCall;
        $call->forceFill(['livemode' => true, 'is_test' => true, 'request_payload' => []])->save();

        return $call;
    });
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(PrePaymentValidationSettings::class)
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$live])
        ->assertSee('Order A-1029')
        ->assertSee(__('webhooks.validation.calls.reason', ['reason' => 'out_of_stock']));
});

it('rotates the secret and removes the URL after re-authentication, warning about links created with validation', function (): void {
    $tenant = activeTenant();
    $endpoint = Validation::endpoint($tenant);
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(PrePaymentValidationSettings::class)
        ->callAction('rotateSecret')
        ->assertHasNoActionErrors()
        ->assertActionMounted('showIssuedSecret');
    expect(Validation::freshEndpoint($endpoint)->previous_secret)->toBe($endpoint->secret);

    $component = Livewire::test(PrePaymentValidationSettings::class)
        ->mountAction('remove');
    $component->assertMountedActionModalSee(__('webhooks.validation.actions.remove_help'));
    $component->callMountedAction();
    $component->assertHasNoActionErrors();
    expect(Validation::in($tenant, false, static fn (): int => ValidationEndpoint::query()->count()))->toBe(0);
});

it('shows each validation of a link on its detail, only to users with webhooks:manage (plan 15.8.7)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['pre_payment_validation' => true]));
    panelValidationCall($tenant, $link, [
        'outcome' => ValidationCallOutcome::Failed,
        'failure_kind' => ValidationFailureKind::Timeout,
        'policy_applied' => ValidationFailurePolicy::FailOpen,
        'response_status' => null,
        'duration_ms' => 5000,
    ]);
    $test = panelValidationCall($tenant, null, ['is_test' => true]);

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])
        ->assertSee(__('webhooks.validation.link_calls.section'))
        ->assertSee(ValidationCallOutcome::Failed->label())
        ->assertSee(ValidationFailureKind::Timeout->label())
        ->assertSee(ValidationFailurePolicy::FailOpen->label())
        ->assertDontSee($test->prefixedId());

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])
        ->assertDontSee(__('webhooks.validation.link_calls.section'));
});
