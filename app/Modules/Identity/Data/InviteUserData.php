<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Access\Enums\SystemRole;

final readonly class InviteUserData
{
    public function __construct(
        public string $email,
        public SystemRole $role,
    ) {}
}
