<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Enums;

enum ImpersonationEndReason: string
{
    case Stopped = 'stopped';
    case Expired = 'expired';
    case Invalid = 'invalid';
}
