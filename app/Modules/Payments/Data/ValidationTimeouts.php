<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use RuntimeException;

/**
 * The merchant's pre-payment validation timeout T
 * (`AXISPAY_VALIDATION_TIMEOUT_SECONDS`, ADR-0061) and every time that has
 * to follow it. T is the only number an operator sets: a payer request, a job
 * and the web server all wait for the merchant, so each limit is T plus the
 * time the rest of its work needs, and none of them can be raised on its own.
 *
 * At T = 5 every value is exactly what the platform used before the setting
 * existed (ADR-0051), so lowering T back to 5 is a pure rollback.
 *
 * Which jobs wait for the merchant (verified in the code): validation runs
 * only inside CaptureAuthorizedPayment, reached by CompleteAuthorizedPaymentJob,
 * by ProcessProviderEventJob and ReconcilePaymentAttemptsJob (both through
 * SyncPaymentAttempt) and by the payer's own request. CloseAttemptOfClosedLinkJob
 * and CheckApiKeyConnectionsJob never call the merchant and keep fixed limits.
 *
 * The configuration files read this class before the container exists, so
 * fromSeconds() only does arithmetic and never throws; assertConfigured()
 * (run at boot and by `axispay:validation-timeouts`) refuses a T or a set of
 * limits that does not hold together.
 */
final readonly class ValidationTimeouts
{
    public const int DEFAULT_SECONDS = 30;

    public const int MIN_SECONDS = 5;

    public const int MAX_SECONDS = 60;

    /** Seconds to connect to the merchant, capped by T itself. */
    private const int CONNECT_SECONDS = 2;

    /**
     * What a payer request needs besides the validation: one bounded Stripe
     * call (CallBudget::worstCallSeconds(), 42 s) and 3 s of its own work.
     */
    private const int REQUEST_EXTRA_SECONDS = 45;

    /** How long the confirmation lease outlives the request budget. */
    private const int LEASE_EXTRA_SECONDS = 40;

    /**
     * What a job needs besides the validation: two bounded Stripe calls
     * (42 s each), the job's own 10 s margin (CallBudget) and a few seconds
     * more (ADR-0051).
     */
    private const int JOB_EXTRA_SECONDS = 110;

    /** Seconds the queue waits after a job's timeout before it hands the job to another worker. */
    private const int RETRY_AFTER_EXTRA_SECONDS = 35;

    /** Seconds the worker's own `--timeout` exceeds the jobs' (a job always stops first). */
    private const int WORKER_EXTRA_SECONDS = 5;

    /** CompleteAuthorizedPaymentJob: its 60 s dispatch delay, 3 tries, the backoffs (30 + 120 s) and a 45 s margin. */
    private const int COMPLETION_DELAY_SECONDS = 60;

    private const int COMPLETION_TRIES = 3;

    private const int COMPLETION_BACKOFF_SECONDS = 150;

    private const int COMPLETION_MARGIN_SECONDS = 45;

    /**
     * ProcessProviderEventJob: 5 tries. Each try may hold the lock for
     * max(timeout + its backoff, retry_after); the backoffs are 10, 30, 120
     * and 600 s, so the tries add up to 5 × timeout + 790 s (the first two
     * waits are the retry_after of 35 s over the timeout). 135 s of margin.
     */
    private const int EVENT_TRIES = 5;

    private const int EVENT_BACKOFFS_AND_MARGIN_SECONDS = 925;

    /** Seconds the web server waits beyond the request budget, and PHP-FPM beyond the web server. */
    private const int NGINX_EXTRA_SECONDS = 10;

    private const int FPM_EXTRA_SECONDS = 5;

    /**
     * `max_execution_time` is a CPU-time guard on Linux (waiting for the
     * merchant does not count), so it sits below the request budget: 30 s
     * at T = 5, as before.
     */
    private const int PHP_EXECUTION_LESS_THAN_BUDGET_SECONDS = 20;

    private function __construct(public int $validationSeconds) {}

    public static function fromSeconds(int $seconds): self
    {
        return new self($seconds);
    }

    /** The timeout the application runs with (`axispay.pre_payment_validation.timeout_seconds`). */
    public static function current(): self
    {
        return new self(config()->integer('axispay.pre_payment_validation.timeout_seconds'));
    }

    public function connectTimeoutSeconds(): int
    {
        return min(self::CONNECT_SECONDS, $this->validationSeconds);
    }

    /** `checkout.request_budget_seconds`: a payer request answers within this. */
    public function requestBudgetSeconds(): int
    {
        return $this->validationSeconds + self::REQUEST_EXTRA_SECONDS;
    }

    /** `checkout.confirmation_lease_seconds`. */
    public function confirmationLeaseSeconds(): int
    {
        return $this->requestBudgetSeconds() + self::LEASE_EXTRA_SECONDS;
    }

    /** `$timeout` of the jobs that wait for the merchant. */
    public function jobTimeoutSeconds(): int
    {
        return $this->validationSeconds + self::JOB_EXTRA_SECONDS;
    }

    /** `retry_after` of every queue connection. */
    public function queueRetryAfterSeconds(): int
    {
        return $this->jobTimeoutSeconds() + self::RETRY_AFTER_EXTRA_SECONDS;
    }

    /** Default of the worker's `--timeout` (`QUEUE_TIMEOUT`). */
    public function workerTimeoutSeconds(): int
    {
        return $this->jobTimeoutSeconds() + self::WORKER_EXTRA_SECONDS;
    }

    /** `$uniqueFor` of CompleteAuthorizedPaymentJob. */
    public function completionUniqueForSeconds(): int
    {
        return self::COMPLETION_DELAY_SECONDS
            + self::COMPLETION_TRIES * $this->jobTimeoutSeconds()
            + self::COMPLETION_BACKOFF_SECONDS
            + self::COMPLETION_MARGIN_SECONDS;
    }

    /** `$uniqueFor` of ProcessProviderEventJob. */
    public function providerEventUniqueForSeconds(): int
    {
        return self::EVENT_TRIES * $this->jobTimeoutSeconds() + self::EVENT_BACKOFFS_AND_MARGIN_SECONDS;
    }

    /** nginx `fastcgi_read_timeout` of the web role. */
    public function nginxReadTimeoutSeconds(): int
    {
        return $this->requestBudgetSeconds() + self::NGINX_EXTRA_SECONDS;
    }

    /** PHP-FPM `request_terminate_timeout` of the web role. */
    public function fpmTerminateTimeoutSeconds(): int
    {
        return $this->nginxReadTimeoutSeconds() + self::FPM_EXTRA_SECONDS;
    }

    /** php.ini `max_execution_time` of the web role. */
    public function phpMaxExecutionSeconds(): int
    {
        return $this->requestBudgetSeconds() - self::PHP_EXECUTION_LESS_THAN_BUDGET_SECONDS;
    }

    /**
     * Refuses to boot with a T outside its range, or with a limit that no
     * longer follows T (an environment override, or a file edited by hand).
     * A queue `retry_after` may be longer than derived, never shorter: a job
     * still running when it passes would be handed to a second worker.
     *
     * @throws RuntimeException
     */
    public static function assertConfigured(): void
    {
        $configured = config('axispay.pre_payment_validation.timeout_seconds');

        if (! is_int($configured) || $configured < self::MIN_SECONDS || $configured > self::MAX_SECONDS) {
            throw new RuntimeException(sprintf(
                'AXISPAY_VALIDATION_TIMEOUT_SECONDS must be a whole number of seconds from %d to %d (ADR-0061).',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
            ));
        }

        $timeouts = self::fromSeconds($configured);

        $mustEqual = [
            'axispay.pre_payment_validation.connect_timeout_seconds' => $timeouts->connectTimeoutSeconds(),
            'axispay.checkout.pre_payment_validation_seconds' => $timeouts->validationSeconds,
            'axispay.checkout.request_budget_seconds' => $timeouts->requestBudgetSeconds(),
            'axispay.checkout.confirmation_lease_seconds' => $timeouts->confirmationLeaseSeconds(),
        ];

        foreach ($mustEqual as $key => $expected) {
            if (config($key) !== $expected) {
                throw new RuntimeException(sprintf(
                    'Config %s must be %d for AXISPAY_VALIDATION_TIMEOUT_SECONDS=%d: it is derived from that one setting, never set on its own (ADR-0061).',
                    $key,
                    $expected,
                    $timeouts->validationSeconds,
                ));
            }
        }

        $connections = config('queue.connections');

        foreach (is_array($connections) ? $connections : [] as $name => $connection) {
            $retryAfter = is_array($connection) ? ($connection['retry_after'] ?? null) : null;

            if ($retryAfter !== null && (! is_int($retryAfter) || $retryAfter < $timeouts->queueRetryAfterSeconds())) {
                throw new RuntimeException(sprintf(
                    'Config queue.connections.%s.retry_after must be at least %d for AXISPAY_VALIDATION_TIMEOUT_SECONDS=%d (job timeout %d + %d); unset its environment override (ADR-0061).',
                    (string) $name,
                    $timeouts->queueRetryAfterSeconds(),
                    $timeouts->validationSeconds,
                    $timeouts->jobTimeoutSeconds(),
                    self::RETRY_AFTER_EXTRA_SECONDS,
                ));
            }
        }
    }
}
