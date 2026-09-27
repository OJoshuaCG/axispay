<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Exceptions\PaymentLinkRejectedException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cancels a link (plan 9.1, 10.5), under a row lock (rules.md rule 8):
 *
 *  - `active` → `canceled`, with the optional reason;
 *  - already `canceled` → returned unchanged (cancel is idempotent);
 *  - `processing` → `409 link_payment_in_progress`;
 *  - `paid` or `expired` → `409 link_not_cancelable`;
 *  - `active` but past its expiry → expired first, then
 *    `409 link_not_cancelable` (the expiry already made it unusable).
 *
 * handle() is the API and system entry point (the API scope `links:cancel`
 * is checked by the route; plan 10.2 only blocks creation for suspended
 * tenants). handleForUser() is the panel entry point: a panel that is not
 * read-only (plan 21.3) and `links:cancel` through the policy. The link's
 * waiting gateway payment is canceled after commit (PaymentLinkClosed,
 * plan 9.1, Phase 4).
 */
final readonly class CancelPaymentLink
{
    public function __construct(
        private PaymentLinkStateMachine $machine,
        private AuditLogger $audit,
        private TenantAccess $access,
    ) {}

    public function handleForUser(User $user, PaymentLink $link, CancelPaymentLinkData $data): PaymentLink
    {
        if (! $this->access->panelWritable($user->tenant_id)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::TenantSuspended, 'The account is read-only in its current state.');
        }

        Gate::forUser($user)->authorize('cancel', $link);

        return $this->handle($link, $data, Actor::user($user->id));
    }

    /**
     * @throws LinkNotCancelableException
     */
    public function handle(PaymentLink $link, CancelPaymentLinkData $data, Actor $actor): PaymentLink
    {
        [$result, $expired] = DB::transaction(function () use ($link, $data, $actor): array {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            if ($locked->status === PaymentLinkStatus::Canceled) {
                return [$locked, false];
            }

            if ($locked->status !== PaymentLinkStatus::Active) {
                throw LinkNotCancelableException::inState($locked->status);
            }

            if ($locked->isPastExpiry()) {
                return [$this->machine->expire($locked), true];
            }

            $this->machine->cancel($locked, $data->reason);

            $this->audit->record(AuditAction::PaymentLinkCanceled, $locked, [
                'before' => ['status' => PaymentLinkStatus::Active->value],
                'after' => ['status' => PaymentLinkStatus::Canceled->value],
                // Free text may hold personal data: the audit keeps only
                // whether there was a reason and its length; the text stays
                // on the link.
                'has_reason' => $data->reason !== null,
                'reason_length' => $data->reason !== null ? mb_strlen($data->reason) : 0,
                'livemode' => $locked->livemode,
            ], actor: $actor);

            return [$locked, false];
        });

        if ($expired) {
            throw LinkNotCancelableException::inState(PaymentLinkStatus::Expired);
        }

        return $result;
    }
}
