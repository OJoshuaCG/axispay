# ADR-0053: The platform logo, brought forward from Phase 8

- **Status:** Accepted (by the project owner, 2026-09-28)
- **Date:** 2026-09-28
- **Source:** master plan section 18 (branding); [ADR-0038](0038-platform-branding-powered-by.md) (platform branding and "Powered by"); [ADR-0014](0014-rbac-permissions.md) (roles and permissions).

## Context

ADR-0038 planned the platform logo for Phase 8, together with tenant logos. Until then the panels and the payment page show the platform name only, next to a stand-in mark.

The project owner wants the platform's own logo now, managed from the platform panel by a superadmin, and a way to choose whether the brand shows the logo, the name, or both. Tenant logos stay in Phase 8.

## Decision

**Who manages it.** Only platform admins holding the new platform permission "manage branding", which the superadmin role holds. Support staff (read-only) do not. Tenant users never reach it. Every change asks the admin to confirm their password again (the usual 10-minute window) and is recorded in the platform audit log: logo uploaded, logo removed, display changed (before and after).

**What the superadmin sets** (on a "Branding" page of the platform panel, in English and Spanish, with a preview of each logo on a light and on a dark background):

- the **platform logo** (required for the logo to show);
- an optional **dark mode logo**; without it, the light one is used in dark mode. Removing the light logo also removes the dark one;
- **what the brand shows:** logo and name (the default), logo only, or name only. It is a platform setting changed from the panel, not a deployment setting.

**Rules that always hold:**

- Without a logo, the name is shown, whatever was chosen.
- The logo's text alternative is always the platform name, so "logo only" still announces the name to screen readers.
- The name always stays in page titles, in the authenticator app entry (two-factor) and in e-mails. E-mails show the name only, never the logo.

**Where it shows:** both panels (the top bar, the mobile menu, and the sign-in, two-factor and invitation pages) and the "Powered by" line at the bottom of the payment page.

**Upload rules** (plan section 18, the same rules tenant logos will use in Phase 8):

- PNG, JPEG or WebP only; SVG is refused. The real type is recognized from the file's content, never from its name.
- At most 1 MB and 2000 × 2000 pixels.
- Every image is converted to a clean PNG, reduced to fit 400 × 120 when larger (never enlarged), keeping transparency. The conversion drops any hidden data the file carried (location, camera details, comments).
- The upload is never kept as the original file.

**Storage and delivery.** The logo is kept in the platform's database (the servers have no permanent disk, so a file would be lost on the next deployment), as platform data that belongs to no tenant. It is delivered by the platform's own address on the panels and on the payment page, so the payment page's rule of loading images only from itself still holds. Each upload gets a new random address: browsers may keep a logo for a year, and a new logo is seen at once because its address changed. The logo is delivered with its exact type and with instructions that stop browsers from guessing another one; it never sets cookies. What the brand shows is remembered by the platform and refreshed as soon as a superadmin changes it.

**Server requirement.** The image conversion needs an image library in the server image. It is part of the application image, so no deployment variable or step is added.

## Consequences

- ADR-0038's Phase 8 item "platform-logo setting and upload in the superadmin panel" is done here. Phase 8 keeps tenant logos, the tenant brand color and `platform_badge_style`, and reuses these upload rules.
- ADR-0038's "until Phase 8, the checkout footer shows the platform name only" no longer applies: the footer follows the superadmin's choice.
- The stand-in mark stays for the "name only" display and whenever no logo exists.
- The platform permission catalog now exists in the product (starting with "manage branding"); future platform permissions join it.
- A JPEG photo's rotation flag is not applied when converting; a logo uploaded sideways must be uploaded upright.
