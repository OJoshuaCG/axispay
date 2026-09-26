<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * What a tenant's API keys may do, given the tenant's status (plan 10.2,
 * 21.3). Creation rules of each resource still apply on top (a suspended
 * tenant has full access but cannot create links).
 */
enum ApiAccess
{
    /** Keys do not authenticate (unknown tenant, closed beyond its window). */
    case None;

    /** Reads only (closed tenant inside its read-only window). */
    case ReadOnly;

    case Full;

    public function allowsMethod(string $method): bool
    {
        return match ($this) {
            self::Full => true,
            self::ReadOnly => in_array(strtoupper($method), ['GET', 'HEAD'], true),
            self::None => false,
        };
    }
}
