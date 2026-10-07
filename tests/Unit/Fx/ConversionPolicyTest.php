<?php

declare(strict_types=1);

use App\Modules\Fx\Enums\ConversionBlockReason;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\Fx\Services\ConversionPolicy;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Data\TenantSettings;

/**
 * The conversion rule (plan 13.2, ADR-009): FX applies only to a USD link,
 * paid with a card issued in Mexico, on a connected account in Mexico; it
 * needs the tenant to have conversion on and the link not to opt out.
 * Every other combination charges the link's own currency.
 */
function policyLink(CurrencyCode $currency, FxMode $mode): PaymentLink
{
    return (new PaymentLink)->forceFill(['currency' => $currency, 'fx_mode' => $mode]);
}

function policySettings(bool $enabled): TenantSettings
{
    return TenantSettings::fromArray(['fx' => ['conversion_enabled' => $enabled]]);
}

it('decides by link currency, account country, card country, tenant switch and link mode', function (
    CurrencyCode $currency,
    ?string $accountCountry,
    ?string $cardCountry,
    bool $tenantEnabled,
    FxMode $linkMode,
    string $expected,
    ?ConversionBlockReason $reason,
): void {
    $decision = (new ConversionPolicy)->decide(policyLink($currency, $linkMode), $accountCountry, $cardCountry, policySettings($tenantEnabled));

    expect($decision->kind())->toBe($expected)
        ->and($decision->blockReason)->toBe($reason)
        ->and($decision->mode)->toBe($expected === 'convert' ? $linkMode : null);
})->with([
    // [link currency, account, card, tenant on, link mode, decision, reason]
    'USD, MX account, MX card, on, banxico' => [CurrencyCode::USD, 'MX', 'MX', true, FxMode::BanxicoFix, 'convert', null],
    'USD, MX account, MX card, on, fixed' => [CurrencyCode::USD, 'MX', 'MX', true, FxMode::Fixed, 'convert', null],
    'USD, MX account, MX card, tenant off' => [CurrencyCode::USD, 'MX', 'MX', false, FxMode::BanxicoFix, 'blocked', ConversionBlockReason::ConversionDisabled],
    'USD, MX account, MX card, link opted out' => [CurrencyCode::USD, 'MX', 'MX', true, FxMode::None, 'blocked', ConversionBlockReason::ConversionDisabled],
    'USD, MX account, MX card, both off' => [CurrencyCode::USD, 'MX', 'MX', false, FxMode::None, 'blocked', ConversionBlockReason::ConversionDisabled],
    'USD, MX account, US card' => [CurrencyCode::USD, 'MX', 'US', true, FxMode::BanxicoFix, 'not_required', null],
    'USD, MX account, US card, tenant off' => [CurrencyCode::USD, 'MX', 'US', false, FxMode::None, 'not_required', null],
    'USD, MX account, unknown card country' => [CurrencyCode::USD, 'MX', null, true, FxMode::BanxicoFix, 'not_required', null],
    'USD, US account, MX card' => [CurrencyCode::USD, 'US', 'MX', true, FxMode::BanxicoFix, 'not_required', null],
    'USD, unknown account country, MX card' => [CurrencyCode::USD, null, 'MX', true, FxMode::BanxicoFix, 'not_required', null],
    'MXN link, MX account, MX card' => [CurrencyCode::MXN, 'MX', 'MX', true, FxMode::None, 'not_required', null],
    'MXN link, MX account, US card' => [CurrencyCode::MXN, 'MX', 'US', true, FxMode::None, 'not_required', null],
    'MXN link, US account, MX card' => [CurrencyCode::MXN, 'US', 'MX', false, FxMode::None, 'not_required', null],
    'lowercase country codes' => [CurrencyCode::USD, 'mx', 'mx', true, FxMode::Fixed, 'convert', null],
]);
