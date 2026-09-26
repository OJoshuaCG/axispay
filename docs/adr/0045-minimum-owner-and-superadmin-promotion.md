# ADR-0045: Minimum one owner per tenant and superadmin ownership promotion

- **Status:** Accepted (by the project owner, 2026-09-25)
- **Date:** 2026-09-25
- **Source:** project owner decision (2026-09-25); master plan sections 7.2, 17.2, 17.3, 17.4, 21.3; [ADR-0031](0031-tenancy-enforcement.md), [ADR-0032](0032-identity-invitations-reauthentication-2fa.md), [ADR-0033](0033-panels-auth-rbac-closure.md), [ADR-0034](0034-phase-1-security-hardening.md), [ADR-0041](0041-production-account-recovery.md), [ADR-0043](0043-tenant-lifecycle-platform-panel.md)

## Context

Plan 17.2 says "Owner: minimum one per tenant; the last owner cannot be removed or demoted". The last-owner rule already protects tenants that have an owner (`OwnerGuard` in `ChangeUserRoles` and `DeactivateUser`), but a tenant could still exist with **no** owner:

- it was created without an owner e-mail, or
- its owner invitation was never accepted, or expired.

Such a tenant has nobody with `gateway:manage`, and the panel offered no fix when the intended person already had an account:

- e-mails are unique across the platform (plan 7.2), so that person cannot be invited again;
- the superadmin could only invite owners;
- impersonation is read-only (plan 17.4);
- `RoleGrantGuard` stops tenant admins from granting owner (plan 17.2: "admin: everything except gateway:manage and transferring ownership").

## Decision

1. **The owner e-mail is required when a tenant is created.**
   - `CreateTenantData::$ownerEmail` is a required string. `Tenancy\Actions\CreateTenant` normalizes it, validates it (`required`, `email`, `max:254`) and refuses an e-mail that already belongs to a user **before** writing anything, so a refusal never leaves a half-created tenant. The error is a `ValidationException` on `owner_email`.
   - The create form marks the field required and runs the same availability check next to the field. If the address is registered between the check and the invitation, the page maps `EmailNotAvailableException` to the same field error.
   - Why no workaround for an existing user: a user belongs to exactly one tenant (plan 7.2), so a person who already has an account can never become a user of a new tenant. The superadmin creates the tenant with another address.
2. **"Has an active owner" is computed, not stored.** At least one user of the tenant with `disabled_at IS NULL` holds the global `owner` role (`roles.team_id IS NULL`, guard `web`) in the tenant's team (`model_has_roles.team_id = tenants.id`).
   - `PlatformAdmin\Services\TenantOwnership` builds it as a correlated `EXISTS` sub-query on `tenants`, through Eloquent only: `Access\Models\Role::tenantUsers()` (a morph-to-many to `User` that, unlike spatie's `Role::users()`, does not resolve the related model from the default auth guard, which is `platform` in the admin panel) with the tenant scope dropped on `User`, pinned to `tenants.id`. The tenant list gets the state of every row in its single query (no N+1).
   - spatie's `HasRoles::roles()` cannot be used there: it filters by the team of the current `TenantContext`, and the platform panel has none.
   - Dropping the scope is allowed because the `PlatformAdmin\` namespace is already on the scope-bypass whitelist (ADR-0031). The whitelist was **not** widened, and no raw SQL, `DB::table()`, `from()` or `join()` touches a tenant or team-scoped table, so `TenantTableAccessRule` stays green.
   - Derived state (`PlatformAdmin\Enums\TenantOwnershipState`): **Active** (an active owner exists), **Pending invitation** (no active owner, an owner invitation is still valid), **None**.
3. **Platform panel.**
   - The tenant list shows an "Owner" badge and has a "No active owner" filter.
   - The tenant view shows a warning above the profile while there is no active owner. It says whether an owner invitation is pending (with its expiry) or has expired, and offers **Invite owner** and **Resend owner invitation** (the existing `InviteTenantOwner` and `ResendInvitation` Actions, same policy checks).
   - Existing tenants without an owner keep working: this is a UI and validation change, with no migration.
4. **"Make owner" (superadmin).** The Users list on the tenant view gets one write action, shown only for an **active** user who is **not** already an owner. It runs `PlatformAdmin\Actions\PromoteToOwner`:
   - `TenantPolicy::promoteOwner`: superadmin and tenant not `closed`;
   - a reason of at least 10 characters;
   - a fresh re-authentication of the platform admin: the existing `Reauthentication` Filament concern and `ReauthenticationWindow`, which already accept a `PlatformAdmin` on the platform guard (password or current 2FA code, rate limited, audited);
   - inside the tenant's context and one transaction: lock the tenant row, then the user row (the same order as `ChangeUserRoles` and `DeactivateUser`), re-check the status, that the user is active and not already an owner, then **add** the owner role;
   - the lookup is tenant-scoped, so another tenant's user is not found (404), and the relation manager never loads one.
5. **Roles are a union.** The user keeps their other roles. Owner already holds every permission, so replacing the roles would only lose the record of the user's previous job, and the owners can adjust roles afterwards from the tenant panel.
6. **Platform-only path.** `PromoteToOwner` does not go through `RoleGrantGuard`. That guard keeps refusing tenant admins who try to grant owner in `ChangeUserRoles` and invitations. Nothing on the tenant side changes.
7. **Audit and notification.**
   - Audited as `owner.promoted` in the platform log (`tenant_id` NULL, `tenant_id` inside the changes) and in the tenant's log, like impersonation: actor platform admin, subject the promoted user, `user_id`, `role`, roles before and after, and the reason.
   - `Access\Notifications\OwnerGrantedByPlatformNotification` e-mails the tenant's active owners and the promoted user. It is queued and sent after commit, in the tenant's default language (EN/ES). It names the tenant, never a person, and never includes the reason.
8. **No "remove owner" for the superadmin.** Out of scope. The last-owner protection stays with the tenant's own role management, and a platform demotion path would need its own reasoning about lock-outs.

## Consequences

- Every new tenant starts with an owner invitation. Support can see which tenants have no active owner and fix them from the panel: invite or resend when the person has no account, or "Make owner" when the person is already a user of that tenant.
- If the tenant's only user with an account is deactivated, it must be reactivated first. Reactivation needs a tenant user with `users:manage`; with no owner at all, that is support's [production account recovery](0041-production-account-recovery.md) CLI.
- The state is computed on every list render. The correlated sub-queries use the `model_has_roles` primary key (`team_id`, `role_id`, `model_id`, `model_type`), the `users (tenant_id, id)` unique key and the `user_invitations (tenant_id, email)` index.
- A superadmin can grant full tenant access, including the payment gateway connection. It is limited by re-authentication, a mandatory reason, audit entries on both sides and an e-mail to the tenant's owners.
