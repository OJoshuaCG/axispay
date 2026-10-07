<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Enums\ProviderDisputeStatus;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Enums\ProviderRefundStatus;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Database\Factories\GatewayConnectionFactory;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

/**
 * StripeGateway refunds and disputes (CRX-13, CRX-14) against Stripe's HTTP
 * layer faked: a refund is made on the PaymentIntent (the adapter alone knows
 * about Stripe's charges), on the connection the payment was made with, under
 * an idempotency key, and Stripe's refund and dispute objects reach the
 * domain as provider-neutral data.
 */
function stripeRefunds(): StripeGateway
{
    return app(StripeGateway::class);
}

function refundsConnection(bool $apiKey = false): GatewayConnection
{
    $tenant = Tenant::factory()->create();

    return GatewayTestHelpers::connection($tenant, false, $apiKey
        ? static fn (GatewayConnectionFactory $f): GatewayConnectionFactory => $f->apiKey()
        : static fn (GatewayConnectionFactory $f): GatewayConnectionFactory => $f->state(['provider_account_id' => 'acct_Refunds01']));
}

it('refunds the PaymentIntent on the connected account under an idempotency key, with our reference in the metadata', function (): void {
    $connection = refundsConnection();
    stripeHttp()->on('post', '/v1/refunds', StripeFixtures::refund('re_New0001', 'pending', reference: '01K6RREFUND000000000000000'));

    $refund = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripeRefunds()->refund($connection, new RefundRequest('pi_Ref0001', 50_000, 'axispay:refund:01K6RREFUND000000000000000', 'requested_by_customer', '01K6RREFUND000000000000000')));
    $request = stripeHttp()->requestsTo('post', '/v1/refunds')[0];

    expect($request['headers']['stripe-account'])->toBe('acct_Refunds01')
        ->and($request['headers']['idempotency-key'])->toBe('axispay:refund:01K6RREFUND000000000000000')
        ->and($request['params'])->toMatchArray(['payment_intent' => 'pi_Ref0001', 'amount' => 50000, 'reason' => 'requested_by_customer', 'metadata' => ['axispay_refund_id' => '01K6RREFUND000000000000000']])
        ->and($request['params'])->not->toHaveKey('charge')
        ->and($request['params'])->not->toHaveKey('refund_application_fee')
        ->and($refund->providerRefundId)->toBe('re_New0001')
        ->and($refund->status)->toBe(ProviderRefundStatus::Pending)
        ->and($refund->amountMinor)->toBe(50000)
        ->and($refund->currency)->toBe('USD')
        ->and($refund->providerPaymentId)->toBe('pi_Ref0001')
        ->and($refund->reference)->toBe('01K6RREFUND000000000000000');
});

it('sends no reason for the neutral "other" and uses the merchant key without an account for api_key', function (): void {
    $connection = refundsConnection(apiKey: true);
    stripeHttp()->on('post', '/v1/refunds', StripeFixtures::refund('re_Key0001', 'succeeded'));

    app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripeRefunds()->refund($connection, new RefundRequest('pi_Ref0001', 100, 'k', null, 'ref')));
    $request = stripeHttp()->requestsTo('post', '/v1/refunds')[0];

    expect($request['headers'])->not->toHaveKey('stripe-account')
        ->and($request['headers']['authorization'])->toContain('rk_test_')
        ->and($request['params'])->not->toHaveKey('reason');
});

it('maps Stripe refund statuses to the neutral ones', function (string $stripe, ProviderRefundStatus $expected): void {
    $connection = refundsConnection();
    stripeHttp()->on('get', '/v1/refunds/re_Map0001', StripeFixtures::refund('re_Map0001', $stripe, changes: $stripe === 'failed' ? ['failure_reason' => 'expired_or_canceled_card'] : []));

    $refund = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripeRefunds()->retrieveRefund($connection, 're_Map0001'));

    expect($refund->status)->toBe($expected)
        ->and($refund->failureReason)->toBe($stripe === 'failed' ? 'expired_or_canceled_card' : null);
})->with([
    'pending' => ['pending', ProviderRefundStatus::Pending],
    'requires action is still pending' => ['requires_action', ProviderRefundStatus::Pending],
    'succeeded' => ['succeeded', ProviderRefundStatus::Succeeded],
    'failed' => ['failed', ProviderRefundStatus::Failed],
    'canceled' => ['canceled', ProviderRefundStatus::Canceled],
]);

it('lists the refunds of a PaymentIntent', function (): void {
    $connection = refundsConnection();
    stripeHttp()->on('get', '/v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => [
        StripeFixtures::refund('re_List0001', 'succeeded'),
        StripeFixtures::refund('re_List0002', 'pending', changes: ['amount' => 1000]),
    ]]);

    $refunds = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripeRefunds()->listRefunds($connection, 'pi_Ref0001'));
    $request = stripeHttp()->requests[0];

    expect($refunds)->toHaveCount(2)
        ->and($refunds[0]->providerRefundId)->toBe('re_List0001')
        ->and($refunds[1]->amountMinor)->toBe(1000)
        ->and($request['params'])->toMatchArray(['payment_intent' => 'pi_Ref0001']);
});

it('maps Stripe errors on a refund: a refusal is a request error, a server error is unavailability', function (): void {
    $connection = refundsConnection();
    stripeHttp()->error('post', '/v1/refunds', 400, code: 'charge_already_refunded');

    $refuse = static fn () => stripeRefunds()->refund($connection, new RefundRequest('pi_Ref0001', 100, 'k1', null, 'r1'));
    $caught = thrownBy(GatewayRequestException::class, static fn () => app(TenantContext::class)->runAsTenant($connection->tenant_id, false, $refuse));
    expect($caught->getMessage())->not->toContain('Fake Stripe error');

    stripeHttp()->error('post', '/v1/refunds', 500, 'api_error');
    thrownBy(GatewayUnavailableException::class, static fn () => app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripeRefunds()->refund($connection, new RefundRequest('pi_Ref0001', 100, 'k2', null, 'r2'))));
});

it('maps Stripe dispute statuses to the neutral ones and reads the evidence deadline', function (string $stripe, ProviderDisputeStatus $expected): void {
    $connection = refundsConnection();
    stripeHttp()->on('get', '/v1/disputes/dp_Map0001', StripeFixtures::dispute('dp_Map0001', $stripe));

    $dispute = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripeRefunds()->retrieveDispute($connection, 'dp_Map0001'));

    expect($dispute->status)->toBe($expected)
        ->and($dispute->providerDisputeId)->toBe('dp_Map0001')
        ->and($dispute->amountMinor)->toBe(150000)
        ->and($dispute->currency)->toBe('USD')
        ->and($dispute->providerPaymentId)->toBe('pi_Ref0001')
        ->and($dispute->reason)->toBe('fraudulent')
        ->and($dispute->evidenceDueBy)->toBe(1790600000);
})->with([
    'needs response' => ['needs_response', ProviderDisputeStatus::NeedsResponse],
    'warning needs response' => ['warning_needs_response', ProviderDisputeStatus::NeedsResponse],
    'under review' => ['under_review', ProviderDisputeStatus::UnderReview],
    'warning under review' => ['warning_under_review', ProviderDisputeStatus::UnderReview],
    'won' => ['won', ProviderDisputeStatus::Won],
    'lost' => ['lost', ProviderDisputeStatus::Lost],
    'warning closed' => ['warning_closed', ProviderDisputeStatus::WarningClosed],
]);

it('maps the refund and dispute events of Stripe to neutral kinds', function (string $type, ProviderEventKind $expected): void {
    expect(stripeRefunds()->eventKind($type, direct: false))->toBe($expected)
        ->and(stripeRefunds()->eventKind($type, direct: true))->toBe($expected);
})->with([
    'refund created' => ['refund.created', ProviderEventKind::RefundUpdated],
    'refund updated' => ['refund.updated', ProviderEventKind::RefundUpdated],
    'refund failed' => ['refund.failed', ProviderEventKind::RefundUpdated],
    'charge refunded' => ['charge.refunded', ProviderEventKind::PaymentRefundsChanged],
    'dispute created' => ['charge.dispute.created', ProviderEventKind::DisputeUpdated],
    'dispute updated' => ['charge.dispute.updated', ProviderEventKind::DisputeUpdated],
    'dispute closed' => ['charge.dispute.closed', ProviderEventKind::DisputeUpdated],
    'a charge succeeded stays unhandled' => ['charge.succeeded', ProviderEventKind::Unhandled],
]);

it('subscribes both webhook destinations to the refund and dispute events', function (): void {
    $events = ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed', 'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed'];

    foreach (['direct_webhook_events', 'connect_webhook_events'] as $list) {
        expect(config('axispay.gateways.stripe.'.$list))->toContain(...$events);
    }
});
