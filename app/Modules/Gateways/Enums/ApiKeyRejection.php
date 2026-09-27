<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Why a pair of merchant keys was refused (plan 12.3.3, 26.2 case 18). The
 * panel shows `message()`; nothing is stored when a key is refused.
 */
enum ApiKeyRejection: string
{
    case SecretKeyNotAllowed = 'secret_key_not_allowed';
    case NotARestrictedKey = 'not_a_restricted_key';
    case InvalidPublishableKey = 'invalid_publishable_key';
    case KeyModesDiffer = 'key_modes_differ';
    case PanelModeMismatch = 'panel_mode_mismatch';
    case KeyRejected = 'key_rejected';
    case AccountNotReadable = 'account_not_readable';
    case CountryNotAllowed = 'country_not_allowed';
    case PublishableKeyRejected = 'publishable_key_rejected';
    case PublishableKeyOtherAccount = 'publishable_key_other_account';
    case MissingPermissions = 'missing_permissions';
    case ExcessivePermissionsNotConfirmed = 'excessive_permissions_not_confirmed';
    case AccountAlreadyLinked = 'account_already_linked';
    case AccountUsesConnect = 'account_uses_connect';
    case KeyAlreadyLinked = 'key_already_linked';
    case DifferentAccount = 'different_account';
    case WebhookEndpointFailed = 'webhook_endpoint_failed';
    case GatewayUnavailable = 'gateway_unavailable';

    /** The form field the error belongs to. */
    public function field(): string
    {
        return match ($this) {
            self::InvalidPublishableKey, self::PublishableKeyRejected, self::PublishableKeyOtherAccount => 'publishable_key',
            self::ExcessivePermissionsNotConfirmed => 'accept_excessive_permissions',
            default => 'restricted_key',
        };
    }

    /**
     * @param  array<string, string>  $replace
     */
    public function message(array $replace = []): string
    {
        return __('gateways.api_key.errors.'.$this->value, $replace);
    }
}
