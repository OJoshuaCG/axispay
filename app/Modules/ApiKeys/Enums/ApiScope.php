<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Enums;

/**
 * Permissions an API key can hold (plan 10.2, 17.1): a subset of the tenant
 * permission catalog. `refunds:create` is the API name of `payments:refund`.
 * The `links:*` scopes (Phase 3), `events:read` (Phase 5) and `payments:read`
 * (ADR-0062) have endpoints; the others are accepted so keys do not need to be reissued when their
 * endpoints arrive.
 */
enum ApiScope: string
{
    case LinksCreate = 'links:create';
    case LinksRead = 'links:read';
    case LinksCancel = 'links:cancel';
    case PaymentsRead = 'payments:read';
    case RefundsCreate = 'refunds:create';
    case RefundsRead = 'refunds:read';
    case EventsRead = 'events:read';

    public function label(): string
    {
        return __('api_keys.scope.'.str_replace(':', '_', $this->value));
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
