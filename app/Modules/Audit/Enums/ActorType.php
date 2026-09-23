<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

enum ActorType: string
{
    case User = 'user';
    case PlatformAdmin = 'platform_admin';
    case ApiKey = 'api_key';
    case System = 'system';

    public function label(): string
    {
        return __('audit.actor_type.'.$this->value);
    }
}
