<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The only cross-tenant reader of payment_links (plan 6.3, 6.5; on the
 * scope-bypass whitelist, config/tenancy.php). It returns identifiers only;
 * callers set the tenant context from each row before touching it. Phase 4
 * adds the public-token lookup of the checkout here.
 */
final class PaymentLinkLookup
{
    /**
     * Active links whose expiry is at or before `$dueAt`, ordered by
     * `(expires_at, id)` (plan 9.1, uses the `(status, expires_at)` index).
     * `$afterExpiresAt` / `$afterId` continue after the last row seen
     * (keyset paging), so rows that stay active (a failure) never block the
     * rest.
     *
     * @return Collection<int, PaymentLink>
     */
    public function dueForExpiration(int $limit, CarbonImmutable $dueAt, ?CarbonImmutable $afterExpiresAt = null, ?string $afterId = null): Collection
    {
        return PaymentLink::query()
            ->withoutGlobalScopes()
            ->select(['id', 'tenant_id', 'livemode', 'expires_at'])
            ->where('status', PaymentLinkStatus::Active->value)
            // Bound with microseconds: DATETIME(6) values would otherwise be
            // compared to a value cut to the second and rows of the same
            // second would be fetched again (and could starve later ones).
            ->where('expires_at', '<=', self::micro($dueAt))
            ->when($afterExpiresAt !== null && $afterId !== null, static fn (Builder $query): Builder => $query->where(
                static fn (Builder $q): Builder => $q->where('expires_at', '>', self::micro($afterExpiresAt))
                    ->orWhere(static fn (Builder $same): Builder => $same->where('expires_at', self::micro($afterExpiresAt))->where('id', '>', $afterId)),
            ))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Every (tenant, mode) pair that has active links, for the gateway
     * reconciliation (identifiers only).
     *
     * @return list<array{tenant_id: string, livemode: bool}>
     */
    public function scopesWithActiveLinks(): array
    {
        $scopes = [];

        PaymentLink::query()
            ->withoutGlobalScopes()
            ->select(['tenant_id', 'livemode'])
            ->where('status', PaymentLinkStatus::Active->value)
            ->distinct()
            ->orderBy('tenant_id')
            ->get()
            ->each(static function (PaymentLink $row) use (&$scopes): void {
                $scopes[] = ['tenant_id' => $row->tenant_id, 'livemode' => $row->livemode];
            });

        return $scopes;
    }

    private static function micro(?CarbonImmutable $time): string
    {
        return ($time ?? CarbonImmutable::now())->utc()->format('Y-m-d H:i:s.u');
    }
}
