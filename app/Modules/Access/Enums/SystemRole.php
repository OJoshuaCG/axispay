<?php

declare(strict_types=1);

namespace App\Modules\Access\Enums;

/**
 * System roles (plan 17.2): permission sets seeded as global roles
 * (`roles.team_id` NULL) and assigned per tenant. Only the superadmin may
 * change them; tenants cannot create or edit roles in the MVP.
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case IntegrationManager = 'integration_manager';
    case Finance = 'finance';
    case LinkCreator = 'link_creator';
    case Viewer = 'viewer';

    /**
     * @return list<TenantPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TenantPermission::cases(),
            self::Admin => array_values(array_filter(
                TenantPermission::cases(),
                static fn (TenantPermission $permission): bool => $permission !== TenantPermission::GatewayManage,
            )),
            self::IntegrationManager => [
                TenantPermission::ApiKeysManage,
                TenantPermission::WebhooksManage,
                TenantPermission::GatewayManage,
                TenantPermission::LinksRead,
                TenantPermission::PaymentsRead,
            ],
            self::Finance => [
                TenantPermission::LinksRead,
                TenantPermission::PaymentsRead,
                TenantPermission::PaymentsRefund,
                TenantPermission::MetricsRead,
                TenantPermission::ReportsExport,
            ],
            self::LinkCreator => [
                TenantPermission::LinksCreate,
                TenantPermission::LinksRead,
                TenantPermission::LinksCancel,
            ],
            self::Viewer => [
                TenantPermission::LinksRead,
                TenantPermission::PaymentsRead,
                TenantPermission::MetricsRead,
            ],
        };
    }

    /**
     * A role is sensitive when it grants a sensitive permission; assigning it
     * notifies the owners and the affected user (plan 17.3).
     */
    public function isSensitive(): bool
    {
        foreach ($this->permissions() as $permission) {
            if ($permission->isSensitive()) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return __('access.role.'.$this->value);
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
