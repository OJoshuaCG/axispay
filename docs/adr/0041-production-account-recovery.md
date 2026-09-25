# ADR-0041: Production account recovery via audited CLI commands

- **Status:** Proposed (pending the project owner's review)
- **Date:** 2026-09-25
- **Source:** master plan sections 17.3 (2FA, password policy, sessions), 17.4 (platform admins) and 23.3 (no secrets in logs); [ADR-0032](0032-identity-invitations-reauthentication-2fa.md) (2FA); [ADR-0034](0034-phase-1-security-hardening.md) (per-account throttling)

## Context

2FA is mandatory for every platform admin and for tenant users with a sensitive permission (ADR-0032). Someone who loses their authenticator and their recovery codes, or forgets their password, is locked out. Until now the only way back was `axispay:dev-reset-2fa`, which refuses to run outside `APP_ENV=local`, and there was no password reset at all: no self-service flow, no panel action. A locked-out platform admin in production required editing the database by hand, without an audit entry and without signing the account out.

## Options considered

1. **Self-service reset by e-mail.** The usual flow for passwords, but an e-mail link must never bypass 2FA, and it needs mail deliverability, templates, token storage and abuse protection. Out of scope for now.
2. **Panel action for superadmins.** A superadmin resets a tenant user or another admin from the admin panel, with re-authentication. Convenient, but a compromised superadmin session could then remove the second factor of any account, and the last superadmin still needs a way out.
3. **Console commands on the server.** Only someone with shell access to the running container can use them.

## Decision

Option 3, for both kinds of accounts and in every environment:

- `axispay:reset-2fa {email} {--type=} {--reason=} {--force}` and `axispay:reset-password {email} {--type=} {--reason=}` (Identity module). The commands are thin; the rules live in the `ResetTwoFactor` and `ResetPassword` Actions, which take an `AccountRecoveryRequest` DTO.
- **Server access is the strong factor.** It is already restricted to operators (Dokploy terminal or SSH). The commands add a mandatory reason (10 to 500 characters), a summary of the account, and a confirmation that defaults to No, with an extra warning for platform admins. The operator verifies the requester's identity out of band before running them.
- **Audit.** `two_factor.reset` / `password.reset`, actor `system`, metadata `{source: "cli", reason, sessions_revoked}`, in the tenant's trail for tenant users and as a platform entry for admins. The entry goes through the usual Redactor, so e-mail addresses or card-like numbers typed into the reason are masked; the password is never part of it. Looking up a tenant user from the console enters the platform context, which is audited (`platform_context.entered`).
- **Effects.** Both Actions lock the account row, delete every session of the account (`database` session driver; with another driver the command says it could not), rotate the "remember me" token and clear the per-account sign-in throttle of both panels. `ResetTwoFactor` checks the stored values without decrypting them, so it also works when `APP_KEY` was lost; an account without 2FA is left untouched. Where 2FA is mandatory, Filament's set-up middleware sends the account to the 2FA set-up on its next sign-in.
- **Passwords never travel on the command line.** `axispay:reset-password` reads the password twice from a hidden prompt and refuses to run without an interactive terminal. It uses `Password::defaults()`, the same policy as account creation. `axispay:reset-2fa` can run unattended only with both `--reason` and `--force`.
- `axispay:dev-reset-2fa` stays as the local convenience (no prompts, `APP_ENV=local` only) and uses the same Action.

A panel-based reset for superadmins (option 2) is deferred. If it is added later, it should reuse these Actions and require re-authentication (plan 17.3) and, for platform admin targets, a second superadmin.

## Consequences

- Recovery needs an operator with server access; there is no self-service path yet. The runbook is in [deployment/dokploy.md](../deployment/dokploy.md#account-recovery).
- Anyone with shell access can reset any account. This was already true (they can read `APP_KEY` and the database); the commands make it audited and consistent instead of a manual `UPDATE`.
- The reason is operator-provided free text stored in the audit log; the prompt says it must not contain secrets.
- Sessions are only revoked with the `database` session driver, which the deployment guides require (`SESSION_DRIVER=database`) and `axispay:doctor` checks.
