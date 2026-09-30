<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Shared\Logging\Redactor;

/**
 * The part of a merchant's answer kept in the logs of the panel (plan 7.6):
 * at most `$limit` bytes of valid UTF-8, without control characters or
 * anything that looks like a secret (Redactor).
 *
 * @internal
 */
final readonly class ResponseExcerpt
{
    public function __construct(private Redactor $redactor) {}

    public function of(string $body, int $limit): ?string
    {
        if ($body === '') {
            return null;
        }

        $limit = max(1, $limit);
        $cut = mb_strcut($body, 0, $limit, 'UTF-8');
        $clean = (string) preg_replace('/[^\P{C}\n\t]/u', '', mb_scrub($cut, 'UTF-8'));

        return mb_strcut($this->redactor->redactString($clean), 0, $limit, 'UTF-8');
    }
}
