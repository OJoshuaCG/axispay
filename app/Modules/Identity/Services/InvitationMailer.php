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
 * Builds the invitation link and sends the invitation e-mail (plan 22): a
 * temporary signed link that expires with the invitation. Used by InviteUser
 * and ResendInvitation, which also hand the same link to the platform panel
 * (ADR-0068). `send` must be called inside the invitation's tenant context.
 */
final class InvitationMailer
{
    public function acceptUrl(#[SensitiveParameter] string $token, CarbonImmutable $expiresAt): string
    {
        return URL::temporarySignedRoute('invitations.show', $expiresAt, ['token' => $token]);
    }

    public function send(string $email, #[SensitiveParameter] string $acceptUrl, SystemRole $role, CarbonImmutable $expiresAt, string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        Notification::route('mail', $email)->notify(new UserInvitationNotification(
            acceptUrl: $acceptUrl,
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
