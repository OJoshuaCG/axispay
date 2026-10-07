<?php

declare(strict_types=1);

use App\Modules\Fx\Enums\FxMode;
use App\Modules\Fx\Enums\FxUnavailableReason;
use App\Modules\Fx\Exceptions\FxUnavailableException;
use App\Modules\Fx\Models\FxQuote;
use App\Modules\Fx\Models\StoredExchangeRate;
use App\Modules\Fx\Services\FxQuoter;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\TenantContext;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\travelTo;

/**
 * FX quotes (plan 13.3, 13.4, ADR-0063): the `fixed` and `banxico_fix`
 * modes, the stored rates, the stale-rate block and the immutability of a
 * quote. The checkout never calls Banxico: it only reads `exchange_rates`.
 */
function fxLink(FxMode $mode, int $amountMinor = 1_230, ?string $rate = null): PaymentLink
{
    $tenant = activeTenant();

    return ApiTestHelpers::link($tenant, false, static fn ($factory) => $factory->state(['currency' => CurrencyCode::USD, 'amount_minor' => $amountMinor, 'fx_mode' => $mode, 'fx_fixed_rate' => $rate]));
}

/**
 * @param  array<string, mixed>  $fx
 */
function fxSettings(array $fx = []): TenantSettings
{
    return TenantSettings::fromArray(['fx' => ['conversion_enabled' => true, ...$fx]]);
}

function storeFix(string $rate, string $date, bool $requiresReview = false): StoredExchangeRate
{
    $row = new StoredExchangeRate;
    $row->forceFill([
        'source' => 'banxico_fix',
        'base_currency' => 'USD',
        'quote_currency' => 'MXN',
        'rate' => $rate,
        'rate_date' => $date,
        'fetched_at' => now(),
        'raw_payload' => ['dato' => $rate],
        'requires_review' => $requiresReview,
    ])->save();

    return $row;
}

function fxReasonOf(Closure $attempt): ?FxUnavailableReason
{
    try {
        $attempt();
    } catch (FxUnavailableException $e) {
        return $e->reason;
    }

    return null;
}

function issueQuote(PaymentLink $link, TenantSettings $settings): FxQuote
{
    return app(TenantContext::class)->runAsTenant($link->tenant_id, $link->livemode, static fn (): FxQuote => app(FxQuoter::class)->issue($link, $settings));
}

beforeEach(function (): void {
    travelTo('2026-10-07 18:00:00');
});

it('quotes the fixed tenant rate exactly: 12.30 USD x 20 = 246.00 MXN, no markup, valid as long as the link', function (): void {
    $link = fxLink(FxMode::Fixed);

    $quote = issueQuote($link, fxSettings(['fixed_rate' => '20', 'markup_bps' => 500]));

    expect($quote->source)->toBe('fixed')
        ->and($quote->rate)->toBe('20.000000')
        ->and($quote->effective_rate)->toBe('20.000000')
        ->and($quote->markup_bps)->toBe(0)
        ->and($quote->exchange_rate_id)->toBeNull()
        ->and($quote->rate_date)->toBeNull()
        ->and($quote->original_amount_minor)->toBe(1_230)
        ->and($quote->original_currency)->toBe(CurrencyCode::USD)
        ->and($quote->converted_amount_minor)->toBe(24_600)
        ->and($quote->converted_currency)->toBe(CurrencyCode::MXN)
        ->and($quote->expires_at->equalTo($link->expires_at))->toBeTrue()
        ->and($quote->payment_link_id)->toBe($link->id)
        ->and($quote->tenant_id)->toBe($link->tenant_id);
});

it('prefers the link rate over the tenant rate in fixed mode', function (): void {
    $link = fxLink(FxMode::Fixed, 1_000, '17.5');

    $quote = issueQuote($link, fxSettings(['fixed_rate' => '20']));

    expect($quote->rate)->toBe('17.500000')->and($quote->converted_amount_minor)->toBe(17_500);
});

it('refuses fixed mode when neither the link nor the tenant has a rate', function (): void {
    $link = fxLink(FxMode::Fixed);

    expect(fxReasonOf(fn () => issueQuote($link, fxSettings())))->toBe(FxUnavailableReason::FixedRateMissing);
});

it('quotes the latest stored Banxico FIX with the tenant markup and the quote validity', function (): void {
    $stored = storeFix('17.2500', '2026-10-07');
    storeFix('17.0000', '2026-10-06');
    $link = fxLink(FxMode::BanxicoFix, 150_000);

    $quote = issueQuote($link, fxSettings(['markup_bps' => 100, 'quote_validity_minutes' => 30]));

    expect($quote->source)->toBe('banxico_fix')
        ->and($quote->exchange_rate_id)->toBe($stored->id)
        ->and($quote->rate)->toBe('17.250000')
        ->and($quote->rate_date)->toBe('2026-10-07')
        ->and($quote->markup_bps)->toBe(100)
        ->and($quote->effective_rate)->toBe('17.422500')
        ->and($quote->converted_amount_minor)->toBe(2_613_375)
        ->and($quote->expires_at->equalTo(now()->addMinutes(30)->toImmutable()))->toBeTrue();
});

it('never uses a FIX flagged for review, falling back to the previous good one', function (): void {
    storeFix('17.0000', '2026-10-06');
    storeFix('25.0000', '2026-10-07', requiresReview: true);

    $quote = issueQuote(fxLink(FxMode::BanxicoFix), fxSettings());

    expect($quote->rate)->toBe('17.000000');
});

it('blocks banxico_fix when there is no stored rate', function (): void {
    expect(fxReasonOf(fn () => issueQuote(fxLink(FxMode::BanxicoFix), fxSettings())))->toBe(FxUnavailableReason::NoFreshRate);
});

it('blocks banxico_fix when the newest FIX is more than 4 calendar days old (plan 13.3)', function (string $rateDate, bool $blocked): void {
    storeFix('17.0000', $rateDate);
    $link = fxLink(FxMode::BanxicoFix);

    if ($blocked) {
        expect(fxReasonOf(fn () => issueQuote($link, fxSettings())))->toBe(FxUnavailableReason::NoFreshRate);

        return;
    }

    expect(issueQuote($link, fxSettings())->rate)->toBe('17.000000');
})->with([
    'published today' => ['2026-10-07', false],
    'four days old (a long weekend)' => ['2026-10-03', false],
    'five days old' => ['2026-10-02', true],
]);

it('does not block the fixed mode when the Banxico rate is stale', function (): void {
    storeFix('17.0000', '2026-09-01');

    expect(issueQuote(fxLink(FxMode::Fixed), fxSettings(['fixed_rate' => '20']))->converted_amount_minor)->toBe(24_600);
});

it('refuses a conversion below the MXN minimum (amount_below_minimum_after_conversion)', function (): void {
    // 0.50 USD (the USD minimum) at 17 = 8.50 MXN, below the MXN 10.00 minimum.
    $link = fxLink(FxMode::Fixed, 50);

    expect(fxReasonOf(fn () => issueQuote($link, fxSettings(['fixed_rate' => '17']))))->toBe(FxUnavailableReason::BelowMinimumAfterConversion);

    expect(issueQuote(fxLink(FxMode::Fixed, 50), fxSettings(['fixed_rate' => '20']))->converted_amount_minor)->toBe(1_000);
});

it('refuses a link that does not convert', function (): void {
    $link = fxLink(FxMode::None);

    expect(fn () => issueQuote($link, fxSettings(['fixed_rate' => '20'])))->toThrow(LogicException::class);
});

it('keeps quotes immutable: a saved quote can neither be changed nor deleted', function (): void {
    $link = fxLink(FxMode::Fixed);
    $quote = issueQuote($link, fxSettings(['fixed_rate' => '20']));

    app(TenantContext::class)->runAsTenant($link->tenant_id, $link->livemode, function () use ($quote): void {
        $fresh = FxQuote::query()->findOrFail($quote->id);

        expect(fn () => $fresh->forceFill(['converted_amount_minor' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $fresh->delete())->toThrow(LogicException::class)
            ->and(FxQuote::query()->findOrFail($quote->id)->converted_amount_minor)->toBe(24_600);
    });
});

it('scopes quotes to their tenant and mode', function (): void {
    $link = fxLink(FxMode::Fixed);
    $quote = issueQuote($link, fxSettings(['fixed_rate' => '20']));
    $other = activeTenant();

    expect(app(TenantContext::class)->runAsTenant($other->id, false, static fn () => FxQuote::query()->whereKey($quote->id)->exists()))->toBeFalse()
        ->and(app(TenantContext::class)->runAsTenant($link->tenant_id, true, static fn () => FxQuote::query()->whereKey($quote->id)->exists()))->toBeFalse()
        ->and(app(TenantContext::class)->runAsTenant($link->tenant_id, false, static fn () => FxQuote::query()->whereKey($quote->id)->exists()))->toBeTrue();
});
