# ADR-0032: Identity: 2FA, invitations and re-authentication

- **Status:** Accepted (Phase 1, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** master plan sections 7.2, 17.3, 22

## Decision

- **2FA:** Filament's built-in TOTP provider (`AppAuthentication`, recoverable, 8 recovery codes) subclassed as `Identity\Auth\AuditedAppAuthentication` to audit enabling, disabling and regenerating codes. Secrets are stored with the `encrypted` cast in `two_factor_secret`; recovery codes are hashed by Filament and stored with `encrypted:array` in `two_factor_recovery_codes` (plan column names). Mandatory for every platform admin (panel-level) and for tenant users holding a sensitive permission (`payments:refund`, `api_keys:manage`, `webhooks:manage`, `gateway:manage`, `users:manage`) through `RequireTwoFactorForSensitiveUsers`, which wraps Filament's middleware. Laravel Fortify is not used.
- **Invitations:** 256-bit random token, only its SHA-256 is stored (`ascii_bin`, unique); the e-mailed link is additionally a Laravel temporary signed URL expiring with the invitation (72 h). Acceptance locks the invitation row, so a token works once. A new invitation for the same e-mail revokes the pending one. Invalid, used, expired and revoked links get the same response (410). The notification is queued after commit (plan 22); the job payload therefore contains the link until it is sent — accepted, since the token is single-use and short-lived and the queue lives in the same database.
- **E-mail uniqueness** is platform-wide (plan 7.2); an invitation to a registered address is refused with a generic message.
- **Re-authentication:** password **or** current TOTP code (plan 17.3), valid 10 minutes (`auth.password_timeout = 600`, standard `auth.password_confirmed_at` session key), rate limited (5 attempts), every attempt audited. Sensitive Actions check the window themselves (`ReauthenticationWindow::ensureConfirmed()`), so the UI cannot skip it. Phase 1 applies it to role changes; later phases add it to the other actions listed in 17.3.
- **Role changes:** an actor can only grant or remove roles whose permissions it holds. This implements "admin: everything except `gateway:manage` and transferring ownership" with permissions only (an admin cannot grant `owner` or `integration_manager`). Users cannot change their own roles; the last active owner cannot lose the role or be deactivated.
- **Passwords:** `Password::defaults()` = 12 characters minimum + `uncompromised()` (HIBP k-anonymity API), switchable with `AXISPAY_PASSWORD_CHECK_UNCOMPROMISED` (off in `phpunit.xml`; tested with `Http::fake()`).
- **Profile:** name, password and 2FA only; e-mail change is not offered in the MVP.
