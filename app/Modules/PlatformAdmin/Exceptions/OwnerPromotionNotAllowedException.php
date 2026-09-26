<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Exceptions;

use DomainException;

final class OwnerPromotionNotAllowedException extends DomainException
{
    /**
     * @param  'reason_required'|'tenant_closed'|'inactive_user'|'already_owner'  $reason  translation key in lang/{en,es}/platform.php `tenants.users.errors`
     */
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function reasonRequired(): self
    {
        return new self('reason_required', 'Granting the owner role requires a reason of at least 10 characters.');
    }

    public static function tenantClosed(): self
    {
        return new self('tenant_closed', 'The owner role cannot be granted in a closed tenant.');
    }

    public static function inactiveUser(): self
    {
        return new self('inactive_user', 'Only an active user can become an owner.');
    }

    public static function alreadyOwner(): self
    {
        return new self('already_owner', 'The user is already an owner.');
    }
}
