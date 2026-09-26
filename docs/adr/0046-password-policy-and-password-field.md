# ADR-0046: One password policy and one password field component

- **Status:** Proposed
- **Date:** 2026-09-26
- **Source:** project owner validation on the deployment (2026-09-26: "I could not see what I typed" on the invitation page); master plan section 17.3; [ADR-0032](0032-identity-invitations-reauthentication-2fa.md), [ADR-0041](0041-production-account-recovery.md), [ADR-0044](0044-panel-ux-theme-language-type-surfaces.md)

## Context

Password entry looked and behaved differently depending on the page:

- the invitation page (Blade) used `<x-input type="password">`: no way to reveal the password, no hint of the rule beyond a hard-coded "at least 12 characters";
- the Filament sign-in, profile and re-authentication fields used Filament's two show/hide buttons (no `aria-pressed`, focus lost when the pressed button disappears, 32px target);
- the policy (plan 17.3: at least 12 characters and not in known data leaks when `AXISPAY_PASSWORD_CHECK_UNCOMPROMISED` is on) lived in a `Password::defaults()` closure. Callers picked it up only if they remembered to: `AcceptInvitation` and `CreatePlatformAdmin` did not validate the password at all (their callers did).

## Decision

1. **`App\Modules\Identity\Support\PasswordPolicy` is the only policy.**
   - `rule()` (the Laravel `Password` rule), `rules()`, `validate()`, `minLength()`, `checksUncompromised()` and `requirements()` (the checklist items). Config is read on every call.
   - `Password::defaults()` returns `PasswordPolicy::rule()`, so Filament and any other `Password::default()` caller get the same rule.
   - Every action that sets a password validates **inside the action**: `AcceptInvitation`, `CreatePlatformAdmin`, `ResetPassword`. Their controller and console callers no longer repeat the rule (one data-leak lookup per attempt). The profile page uses `PasswordField::forNewPassword()`.
   - No new mandatory rule was added. The data-leak check can only run on the server, so the checklist shows it as "checked when you submit". One non-blocking tip ("use a passphrase") is shown.
2. **One component per stack, same API and look.**
   - Blade: `<x-password-input mode="current|new" :confirm="…">`.
   - Filament: `App\Support\Filament\Forms\PasswordField` (`forCurrentPassword()`, `forNewPassword()`, `confirms()`), a `TextInput` subclass.
   - Both render the same partials (`components/password/toggle-icons`, `requirements`, `mismatch`) and the same data attributes.
   - Modes: `current` (sign-in, re-authentication, current password: `autocomplete="current-password"`, no checks) and `new` (`autocomplete="new-password"`, `passwordrules`, live checklist, optional confirmation with a mismatch hint).
3. **Reveal button.**
   - One `<button type="button">`: `aria-controls`, `aria-pressed`, 44x44px.
   - The accessible name stays "Show password" (a toggle's name must not change with its state); the tooltip switches to "Hide password".
   - The icon is chosen by CSS from `aria-pressed`, so no script swaps markup.
4. **Two bindings, one markup.**
   - Blade pages: a small delegated script (`resources/js/password-input.js`) that also keeps focus and caret and masks the fields on submit.
   - Filament: Alpine. Visibility reuses Filament's own `isPasswordRevealed` state (bound to the input type), so a Livewire re-render never resets it and it never reaches the Livewire snapshot.
   - Only the length (checklist) and a boolean (mismatch) are held in memory; the value is never copied anywhere.
5. **Golden rule** (docs/frontend/README.md): every password field uses `<x-password-input>` or `PasswordField`.

## Consequences

- One place to change the policy (`config/axispay.php` → `passwords`, `PasswordPolicy`), and the hints follow automatically.
- A caller that bypasses the UI (a future API endpoint or command) still cannot set a weak password through these actions.
- `AcceptInvitation::handle()` and `CreatePlatformAdmin::handle()` can now throw `ValidationException`; the invitation page and the console command map it to the `password` field / console errors.
- Two small client bindings (vanilla JS and Alpine) must stay in step; both are driven by the same partials and data attributes, and the feature tests assert that shared markup.
- Filament's own show/hide buttons are not used; `PasswordField::revealable()` only sets the flag.
