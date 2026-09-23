<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money;

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Money\Exceptions\InvalidAmountException;
use Brick\Money\Money as BrickMoney;

/**
 * Parses API amounts (plan section 8.2).
 *
 * Only decimal strings are accepted: JSON numbers are rejected with
 * `amount_must_be_string` because many parsers turn them into doubles. The
 * format is validated against the currency exponent, converted to minor units
 * through brick/money (no float), and checked against the configured limits.
 */
final readonly class AmountParser
{
    public function __construct(private CurrencyLimits $limits) {}

    /**
     * @throws InvalidAmountException
     */
    public function parse(
        mixed $amount,
        mixed $currency,
        string $amountParam = 'amount',
        string $currencyParam = 'currency',
    ): Money {
        if (is_int($amount) || is_float($amount)) {
            throw InvalidAmountException::because(
                ApiErrorCode::AmountMustBeString,
                'The amount must be sent as a decimal string, for example "150.50", not as a JSON number.',
                $amountParam,
            );
        }

        if ($amount === null || $amount === '') {
            throw InvalidAmountException::because(
                ApiErrorCode::ParameterMissing,
                "Missing required parameter: {$amountParam}.",
                $amountParam,
            );
        }

        $currencyCode = $this->resolveCurrency($currency, $currencyParam);

        if (! is_string($amount) || preg_match(self::patternFor($currencyCode), $amount) !== 1) {
            throw InvalidAmountException::because(
                ApiErrorCode::AmountInvalid,
                sprintf(
                    'The amount is not a valid %s amount. Use digits with up to %d decimals, no sign, spaces or thousands separators.',
                    $currencyCode->value,
                    $currencyCode->exponent(),
                ),
                $amountParam,
            );
        }

        $money = Money::fromBrick(BrickMoney::of($amount, $currencyCode->value));

        $this->assertWithinLimits($money, $amountParam);

        return $money;
    }

    /**
     * Validation regex for a currency exponent. For exponent 2 this is exactly
     * the plan's `^(0|[1-9][0-9]{0,11})(\.[0-9]{1,2})?$`.
     */
    public static function patternFor(CurrencyCode $currency): string
    {
        $exponent = $currency->exponent();
        $fraction = $exponent > 0 ? "(\\.[0-9]{1,{$exponent}})?" : '';

        return "/^(0|[1-9][0-9]{0,11}){$fraction}$/D";
    }

    private function resolveCurrency(mixed $currency, string $currencyParam): CurrencyCode
    {
        if ($currency === null || $currency === '') {
            throw InvalidAmountException::because(
                ApiErrorCode::ParameterMissing,
                "Missing required parameter: {$currencyParam}.",
                $currencyParam,
            );
        }

        $code = CurrencyCode::tryFromInput($currency);

        if ($code === null || ! $this->limits->isEnabled($code)) {
            $given = is_string($currency) ? $currency : get_debug_type($currency);
            $allowed = implode(', ', array_map(
                static fn (CurrencyCode $enabled): string => $enabled->value,
                $this->limits->enabled(),
            ));

            throw InvalidAmountException::because(
                ApiErrorCode::CurrencyNotSupported,
                "The currency '{$given}' is not supported. Allowed currencies: {$allowed}.",
                $currencyParam,
            );
        }

        return $code;
    }

    private function assertWithinLimits(Money $money, string $amountParam): void
    {
        $min = Money::ofMinor($this->limits->minChargeMinor($money->currency), $money->currency);
        $max = Money::ofMinor($this->limits->maxChargeMinor($money->currency), $money->currency);

        if ($money->minorAmount < $min->minorAmount) {
            throw InvalidAmountException::because(
                ApiErrorCode::AmountBelowMinimum,
                "The amount is below the minimum of {$min->toDecimalString()} {$money->currency->value}.",
                $amountParam,
            );
        }

        if ($money->minorAmount > $max->minorAmount) {
            throw InvalidAmountException::because(
                ApiErrorCode::AmountAboveMaximum,
                "The amount is above the maximum of {$max->toDecimalString()} {$money->currency->value}.",
                $amountParam,
            );
        }
    }
}
