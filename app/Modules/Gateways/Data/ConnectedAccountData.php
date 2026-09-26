<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * State of a gateway account as the gateway reports it (plan 12.1
 * `retrieveAccount`). `requirements` is a provider-neutral snapshot:
 * currently_due, eventually_due, past_due, pending_verification (lists of
 * requirement identifiers), disabled_reason and current_deadline (Unix time).
 */
final readonly class ConnectedAccountData
{
    /**
     * @param  array{currently_due: list<string>, eventually_due: list<string>, past_due: list<string>, pending_verification: list<string>, disabled_reason: string|null, current_deadline: int|null}  $requirements
     */
    public function __construct(
        public string $providerAccountId,
        public ?string $country,
        public ?string $defaultCurrency,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public array $requirements,
    ) {}

    /**
     * @return array{currently_due: list<string>, eventually_due: list<string>, past_due: list<string>, pending_verification: list<string>, disabled_reason: string|null, current_deadline: int|null}
     */
    public static function emptyRequirements(): array
    {
        return ['currently_due' => [], 'eventually_due' => [], 'past_due' => [], 'pending_verification' => [], 'disabled_reason' => null, 'current_deadline' => null];
    }
}
