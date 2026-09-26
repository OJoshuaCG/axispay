<?php

declare(strict_types=1);

namespace App\Support\Filament\Forms;

use App\Modules\Identity\Support\PasswordPolicy;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The one password field of the Filament panels (ADR-0046), twin of the Blade
 * <x-password-input>: same modes, same reveal button, same checklist and
 * mismatch partials (resources/views/components/password/*).
 *
 *     PasswordField::make('password')->forCurrentPassword()  // sign-in, re-auth, current password
 *     PasswordField::make('password')->forNewPassword()      // PasswordPolicy rule + live checklist
 *     PasswordField::make('passwordConfirmation')->confirms('password')
 *     PasswordField::make('restricted_key')->forSecret($show, $hide)  // pasted secrets (API keys)
 *
 * The reveal button replaces Filament's show/hide pair with ONE toggle
 * button (aria-pressed, aria-controls, constant accessible name, tooltip
 * "Show / Hide password", 44px). Visibility lives in Filament's own Alpine
 * state (`isPasswordRevealed`, bound to the input type), so it survives
 * Livewire re-renders and never reaches the Livewire snapshot. The checklist
 * and mismatch hint only keep a length and a boolean in Alpine.
 */
final class PasswordField extends TextInput
{
    public const string MODE_CURRENT = 'current';

    public const string MODE_NEW = 'new';

    public const string MODE_SECRET = 'secret';

    private string $passwordMode = self::MODE_CURRENT;

    private ?string $revealShowLabel = null;

    private ?string $revealHideLabel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->password();
        $this->revealable();
        $this->autocomplete('current-password');
        $this->extraInputAttributes([
            'autocapitalize' => 'none',
            'spellcheck' => 'false',
            'data-password-field' => 'true',
        ], merge: true);
        $this->suffixAction(static fn (PasswordField $component): Action => $component->revealAction());
    }

    /**
     * Only the flag: Filament's revealable() also adds its two show/hide
     * buttons, which this field replaces with a single toggle.
     */
    public function revealable(bool|Closure $condition = true): static
    {
        $this->isRevealable = $condition;

        return $this;
    }

    /** Sign-in, re-authentication, "current password": no policy checks. */
    public function forCurrentPassword(): static
    {
        $this->passwordMode = self::MODE_CURRENT;
        $this->autocomplete('current-password');

        return $this;
    }

    /**
     * Setting or changing a password: PasswordPolicy's rule (read when
     * validating, so config changes apply) and the live checklist under the
     * field. The server rule stays authoritative.
     */
    public function forNewPassword(): static
    {
        $this->passwordMode = self::MODE_NEW;
        $this->autocomplete('new-password');
        $this->rule(static fn (): Password => PasswordPolicy::rule());
        $this->showAllValidationMessages();
        $this->extraInputAttributes(static fn (): array => [
            'passwordrules' => 'minlength: '.PasswordPolicy::minLength().';',
        ], merge: true);
        $this->belowContent(static fn (PasswordField $component): Htmlable => new HtmlString(
            Blade::render('<x-password.requirements :for="$for" />', ['for' => $component->getId()]),
        ));

        return $this;
    }

    /**
     * Confirmation of the new password in the sibling field `$field`: its own
     * reveal button and the "do not match" hint. Pair it with ->same() on the
     * password field (Filament) or `confirmed` (Laravel) on the server.
     */
    public function confirms(string $field): static
    {
        $this->passwordMode = self::MODE_NEW;
        $this->autocomplete('new-password');
        $this->extraInputAttributes(static fn (PasswordField $component): array => [
            'data-password-confirm-for' => $component->siblingId($field),
        ], merge: true);
        $this->belowContent(static fn (PasswordField $component): Htmlable => new HtmlString(
            Blade::render('<x-password.mismatch :for="$for" :of="$of" />', [
                'for' => $component->getId(),
                'of' => $component->siblingId($field),
            ]),
        ));

        return $this;
    }

    /**
     * A pasted secret that is not the user's password (for example a Stripe
     * restricted key): masked with the same reveal button, its own labels,
     * no autocomplete and no offer to save it in a password manager. Never
     * fill it back from stored data.
     */
    public function forSecret(string $showLabel, string $hideLabel): static
    {
        $this->passwordMode = self::MODE_SECRET;
        $this->revealShowLabel = $showLabel;
        $this->revealHideLabel = $hideLabel;
        $this->autocomplete('off');
        $this->extraInputAttributes([
            'data-1p-ignore' => 'true',
            'data-lpignore' => 'true',
            'data-bwignore' => 'true',
        ], merge: true);

        return $this;
    }

    public function getPasswordMode(): string
    {
        return $this->passwordMode;
    }

    /** DOM id of a field in the same container (same state path prefix). */
    private function siblingId(string $field): string
    {
        $id = (string) $this->getId();
        $name = $this->getName();

        return str_ends_with($id, $name) ? Str::beforeLast($id, $name).$field : $field;
    }

    private function revealAction(): Action
    {
        $show = $this->revealShowLabel ?? __('identity.password.show');
        $hide = $this->revealHideLabel ?? __('identity.password.hide');

        return Action::make('togglePasswordVisibility')
            ->label($show)
            ->icon(new HtmlString(Blade::render('<x-password.toggle-icons />')))
            ->color('gray')
            ->visible(fn (): bool => $this->isPasswordRevealable())
            ->extraAttributes([
                'class' => 'pl-password-toggle group',
                'aria-controls' => (string) $this->getId(),
                'data-password-toggle' => 'true',
                'data-label-show' => $show,
                'data-label-hide' => $hide,
                'x-bind:aria-pressed' => "isPasswordRevealed ? 'true' : 'false'",
                'x-bind:title' => 'isPasswordRevealed ? '.Js::from($hide).' : '.Js::from($show),
            ], merge: true)
            ->alpineClickHandler('isPasswordRevealed = ! isPasswordRevealed');
    }
}
