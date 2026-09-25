<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use SensitiveParameter;

/**
 * Sends the invitation e-mail (plan 22): a temporary signed link that expires
 * with the invitation. Used by InviteUser and ResendInvitation. Must be called
 * inside the invitation's tenant context.
 */
final class InvitationMailer
{
    public function send(string $email, #[SensitiveParameter] string $token, SystemRole $role, CarbonImmutable $expiresAt, string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        Notification::route('mail', $email)->notify(new UserInvitationNotification(
            acceptUrl: URL::temporarySignedRoute('invitations.show', $expiresAt, ['token' => $token]),
            tenantName: $tenant->display_name,
            roleLabel: $role->label(),
            expiresAt: $expiresAt,
        ));
    }

    public static function expiresHours(): int
    {
        $hours = config('axispay.invitations.expires_hours', 72);

        return is_int($hours) ? $hours : 72;
    }
}
