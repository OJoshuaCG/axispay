<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Plan 17.1: payment attempts (card, decline codes) need `payments:read`.
 * Read-only in Phase 4; refunds (Phase 7) add their own ability.
 */
final class PaymentAttemptPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(TenantPermission::PaymentsRead->value);
    }

    public function view(User $actor, PaymentAttempt $attempt): bool
    {
        return $actor->tenant_id === $attempt->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, PaymentAttempt $attempt): bool
    {
        return false;
    }

    public function delete(User $actor, PaymentAttempt $attempt): bool
    {
        return false;
    }
}
