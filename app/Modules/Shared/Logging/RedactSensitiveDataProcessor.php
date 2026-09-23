<?php

declare(strict_types=1);

namespace App\Modules\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that scrubs the message, context and extra of every
 * record with the shared Redactor.
 */
final readonly class RedactSensitiveDataProcessor implements ProcessorInterface
{
    public function __construct(private Redactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redactArray($record->context),
            extra: $this->redactor->redactArray($record->extra),
        );
    }
}
