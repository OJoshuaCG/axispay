<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\ValidationResponseProblem;

/**
 * A merchant's answer read against plan 15.8.4 (ValidationResponseParser).
 * `valid` is false when any problem is an error; then `decision` is null and
 * the call is a failure under the endpoint's policy. Warnings name fields
 * that were dropped or corrected.
 */
final readonly class ParsedValidationResponse
{
    /**
     * @param  'approve'|'reject'|null  $decision
     * @param  list<ValidationResponseProblem>  $problems
     */
    public function __construct(
        public ?string $decision,
        public ?string $reasonCode,
        public ?string $payerMessage,
        public bool $cancelLink,
        public array $problems,
    ) {}

    public function valid(): bool
    {
        return $this->decision !== null && $this->errors() === [];
    }

    public function approved(): bool
    {
        return $this->valid() && $this->decision === 'approve';
    }

    /**
     * @return list<ValidationResponseProblem>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->problems, static fn (ValidationResponseProblem $p): bool => $p->isError()));
    }

    /**
     * @return list<ValidationResponseProblem>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->problems, static fn (ValidationResponseProblem $p): bool => ! $p->isError()));
    }
}
