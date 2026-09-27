<?php

declare(strict_types=1);

namespace App\Modules\PayerFields\Services;

use App\Modules\PayerFields\Data\PayerCountries;
use App\Modules\PayerFields\Data\PayerData;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PayerFields\Exceptions\InvalidPayerDataException;
use SensitiveParameter;

/**
 * Validates the payer fields a link collects against the catalog of plan
 * 19.1, using the configuration frozen on the link (tenant default + link
 * override, plan 19.1). Hidden fields are ignored even if sent. Messages come
 * from lang/<locale>/checkout.php; values never appear in errors or logs.
 *
 * | Field           | Rule                                                        |
 * |-----------------|-------------------------------------------------------------|
 * | email           | basic RFC 5322 shape, at most 254 characters                |
 * | full_name       | 2-120 characters                                            |
 * | phone           | country (PayerCountries) + national number → E.164, at most |
 * |                 | 15 digits; Mexico: exactly 10 national digits               |
 * | company_name    | 2-120 characters                                            |
 * | billing_address | country (PayerCountries), line1, city, state, postal code   |
 * |                 | (Mexico: 5 digits); line2 optional                          |
 * | tax_id          | up to 20 letters and digits (format not checked, ADR-023)   |
 * | notes           | up to 500 characters                                        |
 *
 * Deviation from plan 19.1: phone numbers are checked structurally (E.164
 * length and the Mexican 10-digit rule), without libphonenumber; the MX
 * record check of e-mail domains (optional in the plan) is not done.
 */
final class PayerFieldsValidator
{
    public const int TEXT_MIN = 2;

    public const int TEXT_MAX = 120;

    public const int EMAIL_MAX = 254;

    public const int NOTES_MAX = 500;

    public const int TAX_ID_MAX = 20;

    /** @var list<string> */
    public const array ADDRESS_PARTS = ['country', 'line1', 'line2', 'city', 'state', 'postal_code'];

    /**
     * @param  array<string, string>  $config  field => requirement (the link's payer_fields_config)
     * @param  array<mixed>  $input
     *
     * @throws InvalidPayerDataException
     */
    public function validate(array $config, #[SensitiveParameter] array $input): PayerData
    {
        $values = [];
        $errors = [];

        foreach (PayerField::cases() as $field) {
            $requirement = PayerFieldRequirement::tryFrom($config[$field->value] ?? '') ?? $field->platformDefault();

            if ($requirement === PayerFieldRequirement::Hidden) {
                continue;
            }

            $required = $requirement === PayerFieldRequirement::Required;

            if ($field === PayerField::BillingAddress) {
                $this->address($input['billing_address'] ?? null, $required, $values, $errors);

                continue;
            }

            if ($field === PayerField::Phone) {
                $this->phone($input['phone'] ?? null, $input['phone_country'] ?? null, $required, $values, $errors);

                continue;
            }

            $value = self::text($input[$field->value] ?? null);

            if ($value === '') {
                if ($required) {
                    $errors[$field->value] = self::message('required');
                }

                continue;
            }

            $error = match ($field) {
                PayerField::Email => mb_strlen($value) > self::EMAIL_MAX || filter_var($value, FILTER_VALIDATE_EMAIL) === false ? self::message('email') : null,
                PayerField::FullName, PayerField::CompanyName => self::lengthError($value, self::TEXT_MIN, self::TEXT_MAX),
                PayerField::TaxId => preg_match('/^[A-Za-z0-9&Ññ]{1,'.self::TAX_ID_MAX.'}$/u', $value) !== 1 ? self::message('tax_id', ['max' => self::TAX_ID_MAX]) : null,
                default => mb_strlen($value) > self::NOTES_MAX ? self::message('too_long', ['max' => self::NOTES_MAX]) : null, // notes
            };

            if ($error !== null) {
                $errors[$field->value] = $error;

                continue;
            }

            $values[$field->value] = $field === PayerField::Email ? mb_strtolower($value) : ($field === PayerField::TaxId ? mb_strtoupper($value) : $value);
        }

        if ($errors !== []) {
            throw new InvalidPayerDataException($errors);
        }

        return new PayerData($values);
    }

    /**
     * @param  array<string, string|array<string, string>>  $values
     * @param  array<string, string>  $errors
     */
    private function phone(mixed $number, mixed $country, bool $required, array &$values, array &$errors): void
    {
        $digits = preg_replace('/\D+/', '', self::text($number)) ?? '';

        if ($digits === '') {
            if ($required) {
                $errors['phone'] = self::message('required');
            }

            return;
        }

        $country = is_string($country) && PayerCountries::isSupported(strtoupper($country)) ? strtoupper($country) : PayerCountries::DEFAULT;
        $code = PayerCountries::CALLING_CODES[$country];
        $validLength = $country === 'MX' ? strlen($digits) === 10 : strlen($digits) >= 4 && strlen($code.$digits) <= 15;

        if (! $validLength) {
            $errors['phone'] = self::message('phone');

            return;
        }

        $values['phone'] = '+'.$code.$digits;
    }

    /**
     * @param  array<string, string|array<string, string>>  $values
     * @param  array<string, string>  $errors
     */
    private function address(mixed $input, bool $required, array &$values, array &$errors): void
    {
        $input = is_array($input) ? $input : [];
        $parts = [];

        foreach (self::ADDRESS_PARTS as $part) {
            $parts[$part] = self::text($input[$part] ?? null);
        }

        $filled = array_filter($parts, static fn (string $value, string $key): bool => $value !== '' && $key !== 'country', ARRAY_FILTER_USE_BOTH);

        if ($filled === [] && ! $required) {
            return;
        }

        $country = strtoupper($parts['country'] !== '' ? $parts['country'] : PayerCountries::DEFAULT);

        if (! PayerCountries::isSupported($country)) {
            $errors['billing_address.country'] = self::message('country');
        }

        foreach (['line1', 'city', 'state', 'postal_code'] as $part) {
            if ($parts[$part] === '') {
                $errors["billing_address.{$part}"] = self::message('required');
            } elseif (($error = self::lengthError($parts[$part], 1, self::TEXT_MAX)) !== null) {
                $errors["billing_address.{$part}"] = $error;
            }
        }

        if ($parts['line2'] !== '' && ($error = self::lengthError($parts['line2'], 1, self::TEXT_MAX)) !== null) {
            $errors['billing_address.line2'] = $error;
        }

        if ($country === 'MX' && $parts['postal_code'] !== '' && preg_match('/^\d{5}$/', $parts['postal_code']) !== 1) {
            $errors['billing_address.postal_code'] = self::message('postal_code_mx');
        }

        $values['billing_address'] = array_filter([...$parts, 'country' => $country], static fn (string $value): bool => $value !== '');
    }

    private static function lengthError(string $value, int $min, int $max): ?string
    {
        $length = mb_strlen($value);

        return $length < $min || $length > $max ? self::message('length', ['min' => $min, 'max' => $max]) : null;
    }

    /**
     * @param  array<string, int>  $replace
     */
    private static function message(string $key, array $replace = []): string
    {
        $message = __('checkout.errors.'.$key, $replace);

        return is_string($message) ? $message : $key;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
    }
}
