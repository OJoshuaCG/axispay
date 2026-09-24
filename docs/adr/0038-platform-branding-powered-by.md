# ADR-0038: Platform branding: platform logo and an always-visible "Powered by" on the checkout

- **Status:** Accepted (by the project owner, 2026-09-24). Amends plan section 18 (`show_platform_badge`).
- **Date:** 2026-09-24
- **Source:** plan sections 11.3, 18 and 27 (Phases 4 and 8); [ADR-0037](0037-product-naming-axispay.md)

## Context

Plan section 18 defines per-tenant branding (logo, colors, display name) for the white-label checkout and says the platform's name "can be hidden (white label) depending on the plan", through a superadmin setting `show_platform_badge`. Only "Processed by Stripe" was always shown. The plan defined no platform logo and no way to manage one.

The project owner wants payers to see both brands: the merchant's, so they know it is the merchant's payment page, and the platform's, so it is clear which system processes it. Today only the platform display name is configurable (`AXISPAY_DISPLAY_NAME`, ADR-0037); there is no logo anywhere.

## Options considered

1. **Keep the plan as is.** White-label tenants can hide the platform completely.
2. **Always show the platform brand; the setting only controls prominence.** Chosen.
3. **Platform logo as a static file in the image.** Simple, but every change needs a deploy; rejected in favour of managing it from the superadmin panel.

## Decision

- **Platform logo** is managed from the superadmin (`admin`) panel as a platform setting. It follows exactly the upload rules of plan section 18 for tenant logos: PNG, JPEG or WebP only (SVG forbidden), real type validated by magic bytes, at most 1 MB and 2000×2000, re-encoded to a normalized PNG/WebP without EXIF, random versioned file name, served with the correct `Content-Type`, `X-Content-Type-Options: nosniff` and long cache. Changing it is audited.
- **Platform name** keeps coming from `AXISPAY_DISPLAY_NAME` through `Brand::displayName()` (ADR-0037). The logo's `alt` text is that name.
- **Checkout layout (payer-facing):**

  | Area | Shows | Source |
  |---|---|---|
  | Header | Merchant logo and display name | Tenant branding (plan section 18) |
  | Footer | "Powered by" + platform logo and name, and "Processed by Stripe" | Platform setting + `AXISPAY_DISPLAY_NAME` |

  If a logo is missing, the name is shown alone. The footer stays readable at 320 px and in both themes, following the frontend rules.
- **`show_platform_badge` no longer hides the platform.** It becomes a prominence setting, `platform_badge_style` with values `standard` (default) and `subtle`, configurable by the superadmin per tenant or plan. No value removes the footer.
- **Scope of "always visible":** the payer-facing checkout pages (all link states in plan section 11.2, including paid, expired and cancelled). The tenant and superadmin panels already show the platform brand. Emails to payers are out of scope: the plan sends none (ADR-0022).

## Rationale

The merchant keeps a white-label header, so payers trust that the page belongs to the business they are paying. The footer tells them which platform processes the payment, which supports the product's positioning and helps with support and disputes. Reusing the tenant-logo upload rules avoids a second, weaker image pipeline.

## Consequences

- Plan section 18 is updated: "the platform name can be hidden" is replaced by this decision.
- **Phase 4 (checkout):** the page layout includes the header (tenant name; logo when available) and the footer ("Powered by" with the platform name and, once it exists, the logo).
- **Phase 8 (branding):** the platform-logo setting and upload in the superadmin panel are built together with tenant logos, sharing the same upload service; `platform_badge_style` is added.
- Until Phase 8, the checkout footer shows the platform name only.
- Pricing plans can no longer sell "fully hidden platform branding"; at most a subtle badge.
