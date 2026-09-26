<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The one password policy (plan 17.3, ADR-0046): at least
 * `axispay.passwords.min_length` characters (12) and, when
 * `AXISPAY_PASSWORD_CHECK_UNCOMPROMISED` is on, not found in known data leaks.
 *
 * Every place that sets a password validates through here: AcceptInvitation,
 * ResetPassword (and its command), CreatePlatformAdmin (and its command), the
 * profile page (PasswordField::forNewPassword) and Password::defaults(). The
 * client-side checklist of <x-password-input> / PasswordField is built from
 * requirements(), so the hints can never drift from the rule. The server-side
 * rule stays authoritative; the checklist is guidance only.
 *
 * Config is read on every call (never cached), so tests and a config change
 * take effect immediately.
 */
final class PasswordPolicy
{
    public const int DEFAULT_MIN_LENGTH = 12;

    public static function minLength(): int
    {
        $min = config('axispay.passwords.min_length', self::DEFAULT_MIN_LENGTH);

        return is_int($min) ? $min : self::DEFAULT_MIN_LENGTH;
    }

    public static function checksUncompromised(): bool
    {
        return config('axispay.passwords.check_uncompromised', true) === true;
    }

    public static function rule(): Password
    {
        $rule = Password::min(self::minLength());

        return self::checksUncompromised() ? $rule->uncompromised() : $rule;
    }

    /**
     * Rules for a new password field.
     *
     * @return list<string|Password>
     */
    public static function rules(bool $confirmed = false): array
    {
        return $confirmed
            ? ['required', 'string', 'confirmed', self::rule()]
            : ['required', 'string', self::rule()];
    }

    /**
     * @throws ValidationException when the password breaks the policy
     */
    public static function validate(#[SensitiveParameter] string $password, string $attribute = 'password'): void
    {
        Validator::make([$attribute => $password], [$attribute => self::rules()])->validate();
    }

    /**
     * Checklist items shown next to a new-password field, derived from rule().
     * `min` is the length the browser can check live; null means the item can
     * only be verified on submit (the data-leak check needs the server).
     *
     * @return list<array{key: string, label: string, min: int|null}>
     */
    public static function requirements(): array
    {
        $items = [[
            'key' => 'min_length',
            'label' => __('identity.password.requirements.min_length', ['count' => self::minLength()]),
            'min' => self::minLength(),
        ]];

        if (self::checksUncompromised()) {
            $items[] = [
                'key' => 'uncompromised',
                'label' => __('identity.password.requirements.uncompromised'),
                'min' => null,
            ];
        }

        return $items;
    }
}
