<?php

declare(strict_types=1);

namespace App\Modules\Access\Exceptions;

use DomainException;

final class RoleChangeNotAllowedException extends DomainException
{
    /**
     * @param  'last_owner'|'exceeds_permissions'|'no_roles'  $reason  translation key in lang/{en,es}/access.php `errors`
     */
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function lastOwner(): self
    {
        return new self('last_owner', 'The last active owner cannot lose the owner role.');
    }

    public static function exceedsOwnPermissions(): self
    {
        return new self('exceeds_permissions', 'You can only grant or remove roles whose permissions you hold yourself.');
    }

    public static function noRoles(): self
    {
        return new self('no_roles', 'A user needs at least one role.');
    }
}
