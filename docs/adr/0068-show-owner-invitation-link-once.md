# ADR-0068: Show the owner invitation link once in the platform panel

- **Status:** Proposed; it needs the owner's acceptance. Extends [ADR-0043](0043-tenant-lifecycle-platform-panel.md) (invite, resend and revoke from the platform panel) and [ADR-0032](0032-identity-invitations-reauthentication-2fa.md) (single-use invitation tokens).
- **Date:** 2026-10-23
- **Source:** owner request of 2026-10-23: when the e-mail provider fails, a superadmin needs a way to share the invitation link by hand.

## Context

An owner invitation reaches its recipient only by e-mail. Only the SHA-256 of the token is stored, so nobody can recover the link afterwards. When the mail provider fails or the message lands in spam, the tenant has no way to get its first owner, and resending only produces another e-mail.

## Decision

1. **The link is shown once, right after it is issued.** In the platform panel, creating a tenant, "Invite owner" (view page warning and invitations list) and "Resend" open a dialog with the accept link and a Copy button. It is the same signed link that is e-mailed; the e-mail is still sent.
2. **Same rules as a webhook secret** (`ShowsIssuedSecret`). The link lives in a non-public property of the Livewire component: it exists only while the request that issued it renders the dialog, never enters the Livewire snapshot, and is never logged, persisted or put in a job payload. The dialog closes only with "I have copied the link". After that it cannot be shown again; a new link means resending the invitation.
3. **Revocation is unchanged.** Resending replaces the token hash on the same row (the previous link stops working at once), and a new invitation for the same e-mail revokes the pending one, as before.
4. **How the link reaches the panel.** `InviteUser`, `InviteTenantOwner` and `ResendInvitation` return `IssuedInvitation` (the invitation and its link); `CreateTenant` returns `CreatedTenant` (the tenant and its `IssuedInvitation`). `InvitationMailer::acceptUrl()` builds the link once and `send()` e-mails that same link.
5. **Creating a tenant.** The create page normally redirects to the view page; while the link has not been shown, the redirect waits until the dialog is closed.
6. **Scope.** Platform panel only (owner invitations). Invitations sent by a tenant owner from the tenant panel keep the e-mail-only flow.

## Consequences

- A superadmin sees, for a moment, a link that lets someone create the owner account. Superadmins already bootstrap tenants; the dialog warns to share it only with the recipient, over a trusted channel.
- The lists refresh (the `tenant-ownership-changed` event) when the dialog is closed, not when the invitation is issued: that event re-renders the component, which would show the dialog without the link.

## Alternatives considered

- **Storing the plain token (encrypted) to show it again later.** Removes the "single use, hash only" guarantee of ADR-0032 and turns the database into a store of valid links.
- **Putting the link in a toast notification.** Notifications are flashed through the session store and can be dismissed by accident.
- **Passing the link to the view page through the session after creating a tenant.** It would persist the link in the session store; deferring the redirect avoids that.

## Unverified

- The dialog was not checked in a browser (both themes, 320px, Spanish).
