# ADR-0033: Audited impersonation across hosts

- **Status:** Accepted (Phase 1, 2026-09-23)
- **Date:** 2026-09-23
- **Source:** master plan sections 4.1, 17.4

## Context

Plan 17.4: only superadmins, mandatory reason, 30-minute session, read-only by default, visible banner, recorded in the tenant's and the platform's audit logs. The admin and app panels live on different hosts and never share session cookies (plan 4.1).

## Decision

- `StartImpersonation` (superadmin, reason required, active user of an accessible tenant) creates an `impersonation_sessions` row (`expires_at` = now + 30 min) and writes two audit entries: one platform row (`tenant_id` NULL) and one tenant row.
- The admin is sent to a **signed, single-use hand-off URL** on the app host (valid 120 s; token stored as SHA-256, cleared on use). The app host signs in the tenant user on the `web` guard and marks the session (`ImpersonationState`).
- Every tenant-panel request re-checks the row (`EnforceImpersonationWindow`); an expired or invalid impersonation is ended (audited, `end_reason = expired|invalid`) and signed out. The banner offers "Stop", which ends it (`stopped`) and returns to the tenant page on the admin host.
- **Read-only:** a `Gate::before` hook denies every ability except `viewAny` and `view` while the session is an impersonation. The profile page (credentials, 2FA) returns 403, and re-authentication is impossible, so no re-auth-protected action can run as the user. The test/live selector stays available (it only changes what is viewed; the switch is audited with the impersonation metadata).
- The impersonated login does not update the user's `last_login_at`; every audit entry written during the session carries `impersonation.id` and `impersonation.platform_admin_id`.
- 2FA enrollment is not forced on the impersonated user during the session (the admin authenticated with their own mandatory 2FA).

## Consequences

- Write access during impersonation ("read-only by default") would need an explicit, audited elevation; not built in the MVP.
