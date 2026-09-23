<?php

declare(strict_types=1);

namespace App\Modules\Shared\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Log channel "tap" (config/logging.php) that installs the redaction
 * processor. Laravel pushes its Context processor after taps, so request_id
 * and other context data are added first and then redacted as well.
 */
final class RedactSensitiveLogData
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSensitiveDataProcessor(new Redactor));
        }
    }
}
