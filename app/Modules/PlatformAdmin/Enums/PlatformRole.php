<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Enums;

/**
 * Platform roles (plan 17.4). This is a column on platform_admins, not a
 * spatie role: superadmins are never "one more role" of tenant users (ADR-014).
 */
enum PlatformRole: string
{
    case Superadmin = 'superadmin';
    case SupportReadonly = 'support_readonly';

    /**
     * The platform permissions of the role (ADR-014: code checks these).
     *
     * @return list<PlatformPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Superadmin => PlatformPermission::cases(),
            self::SupportReadonly => [],
        };
    }

    public function label(): string
    {
        return __('platform.role.'.$this->value);
    }

    /**
     * @return array<string, string>
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
