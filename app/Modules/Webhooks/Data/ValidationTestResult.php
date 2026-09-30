<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationResponseProblem;
use App\Modules\Webhooks\Models\ValidationCall;

/**
 * The result of "Test validation" (plan 15.8.1), for the panel: the HTTP
 * status, the latency, the decision read, whether the format is valid (the
 * same rules as a real call), the problems found and a sanitized excerpt of
 * the answer. `failureKind` is set when the call itself failed (timeout,
 * connection, TLS, blocked destination, status or invalid answer).
 */
final readonly class ValidationTestResult
{
    /**
     * @param  'approve'|'reject'|null  $decision
     * @param  list<ValidationResponseProblem>  $errors
     * @param  list<ValidationResponseProblem>  $warnings
     */
    public function __construct(
        public ValidationCall $call,
        public ?int $httpStatus,
        public int $latencyMs,
        public ?string $decision,
        public bool $formatValid,
        public array $errors,
        public array $warnings,
        public ?string $responseExcerpt,
        public ?ValidationFailureKind $failureKind,
    ) {}

    /**
     * The problems as translated messages, errors first.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (ValidationResponseProblem $p): string => $p->message(), [...$this->errors, ...$this->warnings]);
    }
}
