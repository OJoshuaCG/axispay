<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\UserInvitation;
use App\Modules\Tenancy\Scopes\TenantScope;

/**
 * Finds an invitation by its token before any tenant is known (the invitee is
 * not logged in). Like PaymentLinkLookup for the checkout, this is the single
 * allowed entry point that reads invitations without the tenant scope
 * (config/tenancy.php whitelist). Callers then switch to the invitation's
 * tenant context.
 */
final readonly class InvitationLookup
{
    public function __construct(private OpaqueTokens $tokens) {}

    public function findByToken(string $token): ?UserInvitation
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return UserInvitation::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('token_hash', $this->tokens->hash($token))
            ->first();
    }
}
