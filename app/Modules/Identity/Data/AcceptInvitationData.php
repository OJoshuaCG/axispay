<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use SensitiveParameter;

final readonly class AcceptInvitationData
{
    public function __construct(
        public string $token,
        public string $name,
        #[SensitiveParameter] public string $password,
    ) {}
}
