<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Enums;

/**
 * Why an API key could not be created (translated in lang/{en,es}/api_keys.php
 * `errors`).
 */
enum ApiKeyRefusal: string
{
    case InvalidName = 'invalid_name';
    case NoScopes = 'no_scopes';
    case TenantReadOnly = 'tenant_read_only';

    public function message(): string
    {
        return __('api_keys.errors.'.$this->value);
    }
}
