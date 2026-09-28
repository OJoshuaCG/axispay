<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The tenant lifts the card-testing block of a link (plan 11.7 rule 4),
 * from the panel (policy `unblockCheckout`: `links:cancel`). Declines before
 * this moment no longer count towards a new block; Turnstile keeps being
 * asked after the next decline. Audited. A sensitive action: it needs a
 * recent re-authentication (plan 17.3), checked here so no UI can skip it.
 */
final readonly class UnblockCheckout
{
    public function __construct(
        private AuditLogger $audit,
        private ReauthenticationWindow $reauthentication,
    ) {}

    /**
     * @throws ReauthenticationRequiredException
     */
    public function handleForUser(User $user, PaymentLink $link): PaymentLink
    {
        Gate::forUser($user)->authorize('unblockCheckout', $link);
        $this->reauthentication->ensureConfirmed();

        return DB::transaction(function () use ($link, $user): PaymentLink {
            $locked = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            if (! $locked->isCheckoutBlocked()) {
                return $locked;
            }

            $locked->forceFill([
                'checkout_blocked_until' => null,
                'checkout_block_reason' => null,
                'checkout_unblocked_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditAction::PaymentLinkCheckoutUnblocked, $locked, ['livemode' => $locked->livemode], actor: Actor::user($user->id));

            return $locked;
        });
    }
}
