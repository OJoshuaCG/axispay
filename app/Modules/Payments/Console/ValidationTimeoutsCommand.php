<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Data\ValidationTimeouts;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Prints, as `NAME=value` lines a shell can evaluate, the limits derived from
 * `AXISPAY_VALIDATION_TIMEOUT_SECONDS` that the container's start-up needs
 * (docker/app/entrypoint.sh, ADR-0061): the web server's and PHP-FPM's
 * request limits and the queue worker's default timeout. They are computed
 * here, never in the shell, so the formulas exist once (ValidationTimeouts).
 *
 * Exits with a failure, and no output on stdout, when the setting or any
 * derived limit is inconsistent, so the container refuses to start.
 */
final class ValidationTimeoutsCommand extends Command
{
    protected $signature = 'axispay:validation-timeouts';

    protected $description = 'Print the limits derived from AXISPAY_VALIDATION_TIMEOUT_SECONDS for the container start-up.';

    public function handle(): int
    {
        try {
            ValidationTimeouts::assertConfigured();
        } catch (RuntimeException $e) {
            $this->getOutput()->getErrorStyle()->writeln($e->getMessage());

            return self::FAILURE;
        }

        $timeouts = ValidationTimeouts::current();

        $this->line('AXISPAY_VALIDATION_TIMEOUT_SECONDS='.$timeouts->validationSeconds);
        $this->line('AXISPAY_JOB_TIMEOUT_SECONDS='.$timeouts->jobTimeoutSeconds());
        $this->line('AXISPAY_QUEUE_RETRY_AFTER_SECONDS='.$timeouts->queueRetryAfterSeconds());
        $this->line('AXISPAY_QUEUE_TIMEOUT_DEFAULT='.$timeouts->workerTimeoutSeconds());
        $this->line('AXISPAY_NGINX_FASTCGI_READ_TIMEOUT='.$timeouts->nginxReadTimeoutSeconds());
        $this->line('AXISPAY_PHP_FPM_REQUEST_TERMINATE_TIMEOUT='.$timeouts->fpmTerminateTimeoutSeconds());
        $this->line('AXISPAY_PHP_MAX_EXECUTION_TIME='.$timeouts->phpMaxExecutionSeconds());

        return self::SUCCESS;
    }
}
