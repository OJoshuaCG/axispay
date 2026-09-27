<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;

/**
 * Tenant panel additions of Phase 4: the link's payment attempts (read-only,
 * `payments:read`), the card-testing block and its unblock action
 * (`links:cancel`), and isolation (another tenant's link is a 404).
 */
it('shows the payment attempts of a link to users with payments:read', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Finance]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])
        ->assertOk()
        ->assertSee(__('payments.attempts.section'))
        ->assertSee(__('payments.attempt_status.requires_payment_method'))
        ->assertSee('generic_decline')
        ->assertSee('•••• 4242', false)
        ->assertSee(Checkout::attempts($link)[0]->prefixedId());
});

it('hides the attempts from users without payments:read', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])
        ->assertOk()
        ->assertDontSee(__('payments.attempts.section'))
        ->assertDontSee('generic_decline');
});

it('shows the block and lets an owner unblock the link', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->state(['checkout_blocked_until' => now()->addDay(), 'checkout_block_reason' => 'card_testing']));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])
        ->assertSee(__('payments.checkout_block.callout_help'))
        ->callAction('unblockCheckout')
        ->assertHasNoActionErrors();

    expect(Checkout::freshLink($link)->isCheckoutBlocked())->toBeFalse();
});

it('does not offer the unblock action without links:cancel', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->state(['checkout_blocked_until' => now()->addDay()]));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])->assertActionHidden('unblockCheckout');
});

it('answers 404 for another tenant link detail with attempts (isolation)', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_decline');
    $other = ApiTestHelpers::readyTenant();
    actingAsTenantUser(tenantUser($other, [SystemRole::Owner]));

    \Pest\Laravel\get(appUrl('/payment-links/'.$link->id))->assertNotFound();
});
