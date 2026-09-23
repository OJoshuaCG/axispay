<?php

declare(strict_types=1);

namespace App\Modules\Audit\Exceptions;

use LogicException;

/**
 * The audit log is append-only (plan 7.1). A database trigger enforces the
 * same rule for writes that bypass Eloquent.
 */
final class AuditLogImmutableException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Audit log entries cannot be modified or deleted.');
    }
}
