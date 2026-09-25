# ADR-0043: Tenant lifecycle management in the platform panel

- **Status:** Proposed
- **Date:** 2026-09-25
- **Source:** project owner request (2026-09-25); master plan sections 7.1, 7.2, 17.3, 17.4, 21.3, 22; [ADR-0031](0031-tenancy-enforcement.md), [ADR-0032](0032-identity-invitations-reauthentication-2fa.md), [ADR-0034](0034-phase-1-security-hardening.md)

## Context

The superadmin could create a tenant, view it, change its status and impersonate its users. A tenant created without `owner_email` had no way in, a pending invitation could not be resent or revoked, and the profile could not be corrected.

## Decision

1. **Edit profile.** `Tenancy\Actions\UpdateTenantProfile` (DTO `UpdateTenantProfileData`) edits `legal_name`, `display_name`, `timezone`, `default_locale` and `support_email` with the creation rules (also enforced in the Action). Superadmin only, not on a `closed` tenant, row lock, audited as `tenant.updated` with the changed field names; before/after values only for non-personal fields. `support_email` is PII: only its name is recorded. The status stays out of the form: it changes only through "Change status" (reason, allowed transitions, owners notified).
2. **Invite an owner later.** `Tenancy\Actions\InviteTenantOwner` runs `Identity\Actions\InviteUser` inside `TenantContext::runAsTenant()`, exactly like `CreateTenant`: no tenant inviter (`invitedBy` null), role **owner only** (the platform bootstraps the tenant; the owner invites everyone else from the tenant panel). `InviteUser` gained an optional `platformAdmin` argument: the audit entries (`invitation.created`, `invitation.revoked` for a superseded one, `invitation.refused`) name the acting admin (`actor_type = platform_admin`, `source: platform`), also from the CLI. `RoleGrantGuard` and re-authentication still apply to tenant users only: the platform path is authorized by the platform policy before `InviteUser` runs. Platform-wide e-mail uniqueness and "a new invitation supersedes the pending one" are unchanged.
3. **Throttle.** The per-tenant invitation throttle (`axispay.invitations.max_per_hour`) moved to `Identity\Services\InvitationThrottle` and now also counts platform invitations and resends (before, a null inviter skipped it).
4. **Resend.** `Identity\Actions\ResendInvitation`, for **pending or expired** invitations: new 256-bit token and new 72-hour expiry on the same row, under a row lock, then a new e-mail. Only the SHA-256 of the token is stored, so replacing `token_hash` invalidates the old link at once (its lookup finds nothing: 410, like any invalid link). Refused for accepted or revoked invitations and when the address has an account by now. Audited as `invitation.resent` (role, previous status; no e-mail, no token).
5. **Revoke.** `Identity\Actions\RevokeInvitation`, for **pending** invitations only; sets `revoked_at`, audited `invitation.revoked` (`reason: revoked`). Allowed on a closed tenant too, to kill a pending link.
6. **Invitation status** is derived, not stored (`Identity\Enums\InvitationStatus`): accepted and revoked are final and win over expiry; otherwise pending until `expires_at`, then expired.
7. **Authorization** (`PlatformAdmin\Policies\TenantPolicy`, platform guard): `update` (superadmin, not closed), `sendInvitations` (superadmin, tenant not closed: invite owner, resend), `revokeInvitations` (superadmin), `viewMembers` (every platform admin). The Actions authorize themselves; the panel hides what the policy denies.
8. **Lists on the tenant view.** Two relation managers: invitations (e-mail, role, status, invited, expires; invite owner, resend, revoke) and users (read-only: name, e-mail, roles, active, 2FA yes/no, last sign-in). The admin panel has no tenant context, so their queries drop the fail-closed tenant scope and keep the relation's `tenant_id = <viewed tenant>` constraint; the `PlatformAdmin\` namespace is already on the scope-bypass whitelist (ADR-0031, no new entry). Records are resolved through the same query, so another tenant's invitation or user can be neither listed nor acted on. Role assignments are team-scoped, so they are read inside the user's tenant context. For `support_readonly`, e-mails are masked (plan 17.4); superadmins see them in full.
9. **No hard delete.** Tenants are never deleted: `audit_logs.tenant_id` references `tenants` with `ON DELETE RESTRICT` and the audit log is append-only (triggers). A tenant is retired by changing its status to `closed`, which already requires retyping the display name (plan 21.3). The view page says so next to the status.
10. **`php artisan axispay:mail-test {email} {--queue}`** (Shared module): sends a small test message synchronously through the default mailer, or through the queue with `--queue`; prints mailer, transport, host, port, scheme, username, from address and name, never the password (only whether it is set); on a transport error prints the exception class and message and exits 1. Safe in production.

## Consequences

- Support can recover a tenant that nobody can enter without database access.
- A resend loop is bounded by the same hourly limit as new invitations.
- Resending keeps one row per invitation; the audit log holds the history.
- The tenant panel does not offer resend or revoke yet; the Actions accept a platform admin only. Offering them to tenant owners later needs a tenant-side policy (`users:manage` plus `RoleGrantGuard` on the invitation's role).
