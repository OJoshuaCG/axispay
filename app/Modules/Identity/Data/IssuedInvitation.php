<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Models\UserInvitation;
use SensitiveParameter;

/**
 * Result of InviteUser and ResendInvitation: the stored invitation and the
 * signed accept link that was e-mailed (ADR-0068). Only the token's hash is
 * stored, so the link exists only here: the platform panel shows it once so
 * a superadmin can share it by hand when the e-mail does not arrive. Never
 * logged, persisted or put in a job payload.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public UserInvitation $invitation,
        #[SensitiveParameter] public string $acceptUrl,
    ) {}
}
