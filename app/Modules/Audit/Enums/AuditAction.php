<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

/**
 * Catalog of audited actions (plan 7.1 `audit_logs.action`). The value is what
 * is stored; labels live in lang/{en,es}/audit.php.
 */
enum AuditAction: string
{
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case LoginThrottled = 'auth.login_throttled';
    case Logout = 'auth.logout';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case TwoFactorRecoveryCodesRegenerated = 'two_factor.recovery_codes_regenerated';
    case ReauthenticationConfirmed = 'reauthentication.confirmed';
    case ReauthenticationFailed = 'reauthentication.failed';
    case InvitationCreated = 'invitation.created';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationRefused = 'invitation.refused';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case RoleAssigned = 'role.assigned';
    case RoleRevoked = 'role.revoked';
    case TenantCreated = 'tenant.created';
    case TenantStatusChanged = 'tenant.status_changed';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationEnded = 'impersonation.ended';
    case PlatformContextEntered = 'platform_context.entered';
    case LivemodeSwitched = 'livemode.switched';
    case PlatformAdminCreated = 'platform_admin.created';

    public function label(): string
    {
        return __('audit.action.'.str_replace('.', '_', $this->value));
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
