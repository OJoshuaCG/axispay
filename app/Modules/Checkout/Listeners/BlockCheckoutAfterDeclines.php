<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Listeners;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Checkout\Notifications\CheckoutBlockedNotification;
use App\Modules\Checkout\Services\CheckoutNotificationRecipients;
use App\Modules\Checkout\Services\LinkDeclineCounter;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Events\PaymentDeclined;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Plan 11.7 rule 4: a link with `long_block_declines` declines stops taking
 * payments for `long_block_hours`, the tenant's owners and the users who may
 * lift the block (`links:cancel`) get an e-mail, and the block is audited.
 * The superadmin alert on decline spikes is Phase 9 (plan 24.3); until then
 * an alert-level log line.
 */
final readonly class BlockCheckoutAfterDeclines
{
    public const string REASON = 'card_testing';

    public function __construct(
        private TenantContext $context,
        private LinkDeclineCounter $declines,
        private AuditLogger $audit,
        private CheckoutNotificationRecipients $recipients,
        private TenantAccess $access,
    ) {}

    public function handle(PaymentDeclined $event): void
    {
        $this->context->runAsTenant($event->tenantId, $event->livemode, function () use ($event): void {
            $blocked = DB::transaction(function () use ($event): ?PaymentLink {
                $link = PaymentLink::query()->lockForUpdate()->find($event->paymentLinkId);

                if ($link === null || $link->isCheckoutBlocked() || $this->declines->declinesOf($link) < max(1, config()->integer('axispay.checkout.long_block_declines'))) {
                    return null;
                }

                $link->forceFill([
                    'checkout_blocked_until' => CarbonImmutable::now()->addHours(max(1, config()->integer('axispay.checkout.long_block_hours'))),
                    'checkout_block_reason' => self::REASON,
                ])->save();

                $this->audit->record(AuditAction::PaymentLinkCheckoutBlocked, $link, [
                    'reason' => self::REASON,
                    'until' => $link->checkout_blocked_until?->toIso8601String(),
                    'livemode' => $link->livemode,
                ], actor: Actor::system());

                return $link;
            });

            if ($blocked === null) {
                return;
            }

            Log::alert('A payment link was blocked for possible card testing.', ['payment_link_id' => $blocked->id, 'tenant_id' => $blocked->tenant_id, 'livemode' => $blocked->livemode]);

            Notification::send(
                $this->recipients->of($blocked->tenant_id),
                (new CheckoutBlockedNotification($blocked->id, $blocked->livemode))->locale($this->access->defaultLocale($blocked->tenant_id)),
            );
        });
    }
}
