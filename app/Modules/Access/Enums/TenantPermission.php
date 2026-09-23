<?php

declare(strict_types=1);

namespace App\Modules\Access\Enums;

/**
 * Tenant permission catalog (plan 17.1). Code checks these, never role names
 * (ADR-014). Seeded by Database\Seeders\PermissionCatalogSeeder.
 */
enum TenantPermission: string
{
    case LinksCreate = 'links:create';
    case LinksRead = 'links:read';
    case LinksCancel = 'links:cancel';
    case PaymentsRead = 'payments:read';
    case PaymentsRefund = 'payments:refund';
    case MetricsRead = 'metrics:read';
    case ReportsExport = 'reports:export';
    case ApiKeysManage = 'api_keys:manage';
    case WebhooksManage = 'webhooks:manage';
    case GatewayManage = 'gateway:manage';
    case SettingsManage = 'settings:manage';
    case UsersManage = 'users:manage';
    case AuditRead = 'audit:read';

    /** Guard of the tenant users (config/auth.php). */
    public const string GUARD = 'web';

    /**
     * Plan 17.1 marks these as sensitive: holding any of them makes 2FA
     * mandatory (plan 17.3).
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::PaymentsRefund,
            self::ApiKeysManage,
            self::WebhooksManage,
            self::GatewayManage,
            self::UsersManage,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function sensitive(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $permission): bool => $permission->isSensitive()));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }

    public function label(): string
    {
        return __('access.permission.'.str_replace(':', '_', $this->value));
    }
}
