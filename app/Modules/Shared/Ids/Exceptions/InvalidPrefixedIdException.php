<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ids\Exceptions;

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\ResourceType;

/**
 * A public ID is malformed or has the wrong type prefix. Rendered as a plain
 * `404 resource_not_found` so it reveals nothing about other resources.
 */
final class InvalidPrefixedIdException extends ApiException
{
    public static function for(ResourceType $expected): self
    {
        return new self(ApiErrorCode::ResourceNotFound, "No such {$expected->name} resource.");
    }
}
