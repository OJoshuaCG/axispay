<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Data;

use App\Modules\PlatformAdmin\Models\ImpersonationSession;

/**
 * Result of StartImpersonation: the session and the single-use hand-off URL
 * on the app host (signed, valid for a couple of minutes).
 */
final readonly class StartedImpersonation
{
    public function __construct(
        public ImpersonationSession $session,
        public string $handoffUrl,
    ) {}
}
