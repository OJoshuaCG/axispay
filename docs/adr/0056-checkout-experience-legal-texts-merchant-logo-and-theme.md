# ADR-0056: The checkout experience: legal texts, the merchant's logo and a theme choice, brought forward from Phases 8 and 10

- **Status:** Accepted (by the project owner, 2026-09-30). Parts A, B and C are implemented. Amends plan sections 7.1, 11.3, 17.1, 18 and 19.2, [ADR-0051](0051-checkout-and-card-payments-phase-4.md) (no theme toggle on the payment page) and [ADR-0053](0053-platform-logo-brought-forward.md) (tenant logos kept at 400 × 120).
- **Date:** 2026-09-30
- **Source:** master plan sections 7.1, 11.3, 17.1, 18, 19.2, 29 (open questions 7 and 9) and the go-live checklist; [ADR-0038](0038-platform-branding-powered-by.md), [ADR-0051](0051-checkout-and-card-payments-phase-4.md), [ADR-0053](0053-platform-logo-brought-forward.md); the checkout design spec, [docs/frontend/checkout-design.md](../frontend/checkout-design.md).

## Context

The payment page only collects payer data when the merchant has a privacy notice (ADR-0051). The platform stores one web address per merchant for it, but nothing in either panel lets anyone set it, so in practice every payment page collects no payer data, and the tenant panel tells merchants to "ask the platform administrator". Merchants also have no way to show their terms and conditions, and the platform has no place for its own privacy notice and terms, which the go-live checklist requires.

The plan put the merchant's branding and privacy notice in Phase 8 and the platform's legal texts in Phase 10. The project owner wants the payment page to be complete and trustworthy now: the merchant's legal texts and logo on it, the platform's legal texts reachable from it, and a clearer layout with a light or dark choice.

## Decision

### A. Legal texts (implemented now)

**The merchant's documents.** In the tenant panel, a "Legal" page (under Settings) lets the merchant publish two documents: a **privacy notice** and **terms and conditions**. Each one is, independently:

- a **text** written on the page, in simple formatting (paragraphs, headings, lists, bold and italics, links), up to 50,000 characters; any web code in it is removed, and only ordinary web and e-mail links are kept;
- or a **link** to a page elsewhere (an address starting with `http://` or `https://`, without a user name or password);
- or not set.

Changing them needs a new tenant permission, **"manage the privacy notice and terms"**, held by the owner and admin roles (not sensitive: it does not require two-factor authentication or the password again). Every change and removal is recorded in the tenant's audit log with the kind of document, how it is published and, for texts, only their length and fingerprint, never the text itself. A suspended or closed tenant's panel only shows them, and a platform admin viewing as the tenant cannot change them.

**On the payment page.** Below the amount, the page shows "Privacy notice · Terms" for whichever documents the merchant published, in every state of the page:

- a **text** opens in a window over the page (labelled with the document's name, closed with Escape, the close button or a click outside, returning to the link). Without JavaScript, or when the payer opens it in a new tab, the link leads to the document's own page on the payment address, under the link's address (for example `/l/…/legal/privacy`), which answers "link not found" like the rest of the page when the link or the document does not exist;
- a **link** opens the merchant's page in a new tab, without telling it where the payer came from.

The payment page's security rules do not change: the window is part of the page and needs no new source.

**The payer-data rule of ADR-0051 is kept:** without a privacy notice, the page collects no payer data. "Has a privacy notice" now means a text **or** a link is published; with either, the link's payer fields are collected again. The line under the payer fields links the notice the same way. The tenant panel's warning on affected links now points to the Legal page instead of the platform administrator.

**The platform's documents.** In the platform panel, a "Legal" page lets the superadmin publish the platform's privacy notice and terms, with the same text-or-link model. It needs a new platform permission, **"manage legal texts"**, held by the superadmin role; every change asks for the password again (the usual 10-minute window) and is recorded in the platform audit log, like Branding (ADR-0053).

They are shown on a public page of the payment address, **`/legal`**, with one section per document (reachable directly as `/legal#privacy` and `/legal#terms`); a link document shows a link out. While neither is published, `/legal` answers "not found". The "Powered by" line at the bottom of every payment page (ADR-0038) links to `/legal` when at least one platform document exists, and is plain text otherwise.

**Storage.** The merchant's documents are kept per merchant (not per test or live mode: they are the merchant's public identity, like its display name, and hold no payment data), one per kind; the platform's documents are platform data, one per kind. The privacy notice addresses merchants already had are moved over as link documents, and the old single address is retired, so there is one source of truth.

### B. The merchant's logo (implemented)

- The merchant uploads a logo in the tenant panel, with the same checks as the platform logo (ADR-0053): PNG, JPEG or WebP recognized from the file's content, SVG refused, at most 1 MB and 2000 × 2000 pixels, converted to a clean PNG without hidden data.
- It is kept at up to **800 × 240** pixels (reduced when larger, never enlarged), so it stays sharp shown large. This amends plan section 18 (400 × 120) and ADR-0053's note that tenant logos stay 400 × 120.
- It is shown **only on the payment pages**, at the top, centered and large, with the merchant's name as its text alternative. Both panels keep showing the platform logo.

How it was built:

- **Where it is set.** A "Brand" page in the tenant panel (under Settings) with a preview on a light and on a dark page. It needs the existing tenant permission **"manage settings"**, which plan section 17.1 already reserves for the merchant's branding, held by the owner and admin roles (no new permission; not sensitive: no password again). A suspended or closed tenant's panel only shows it, and a platform admin viewing as the tenant cannot change it. Every upload and removal is recorded in the tenant's audit log with the variant, the image's size and its fingerprint, never the image.
- **An optional dark version.** Besides the logo, the merchant may upload a version for dark backgrounds. Each one is replaced or removed on its own; removing the logo also removes the dark version (a dark version alone is never shown). In dark theme, without a dark version, the logo is shown on a light plate so a logo drawn for white backgrounds stays readable.
- **Which pages.** Every state of the payment page and the merchant's legal document pages under the link. Without a logo, the merchant's name is shown as before.
- **Serving.** Only on the payment address, under the link's own address (for example `/l/…/logo/light/…`), so it reveals nothing the page does not already show and only ever the link's own merchant's logo; anything else answers "link not found" like the rest of the page. Each upload gets a new address, so browsers keep it for a year and never show an old logo; it sets no cookie. Like the legal texts and for the same reason, it is kept per merchant, not per test or live mode.

### C. The payment page's layout and theme (implemented)

- **Two cards from desktop widths:** on the left, the payment details (merchant, amount, description and the legal links); on the right, the payer fields, the card form and the Pay button. On phones they stack in that order.
- **A light, dark or system theme choice** on the payment page, with the same control the other public pages use. This amends ADR-0051's "no theme toggle on the payment page": the page still follows the device until the payer chooses. Stripe's card form follows the chosen theme.
- **A less crowded footer**, keeping "Powered by" (ADR-0038) and "Processed by Stripe" visible in every state.

How it was built (where it differs from or details the above):

- **Default theme: light**, like the panels and every other page (ADR-0044), not the device's theme: the owner found the page "all dark" on dark devices. "System" follows the device when the payer picks it. The choice is remembered in the payer's browser for the payment address only.
- **The cards** sit on a light-gray background in light theme (white cards with a fine border and a soft shadow); in dark theme they are flat, outlined by their border. The details card is no longer pinned while scrolling, so a long description is always reachable.
- **The details card** shows the "Total to pay" label on screen; a very large total puts its currency code on a second line on narrow phones instead of running off the screen.
- **Single card for outcomes:** paid, expired, canceled and blocked links show one card, with the outcome first and then what the payment was for; while a payment is processing both cards stay. The "link not found" and error pages show one card, with the language and theme controls only.
- **Footer:** the merchant's help line ("Questions about your payment? Email …") and, apart from it, "Powered by" · "Processed by Stripe"; "Powered by" opens the platform's legal page in a new tab, so the payer does not leave the payment.
- **The legal-text window** fills the screen on phones and is a centered panel on larger screens, with the merchant's name, the title, the text and a Close button; the page behind it does not move.

## What stays in later phases

- **Phase 8:** the merchant's brand colors and background style, the per-tenant choice of payer-data retention (6, 12, 24 or 60 months) and the daily purges of payer data and of the payer's address and browser (ADR-0051).
- **Phase 10 / go-live:** the legal contract between the platform and each merchant (the data-processing agreement) and the actual wording of the platform's privacy notice and terms, to be reviewed with legal counsel (plan section 29, questions 7 and 9). This decision provides where they are published, not their content.

## Consequences

- Merchants can collect payer data as soon as they publish a privacy notice themselves; nobody needs the platform administrator for it.
- Payers can read the merchant's privacy notice and terms, and the platform's, from every payment page, with or without JavaScript.
- Deploying part A needs only the usual database update; the existing privacy notice addresses keep working as link documents. Rolling back returns link privacy notices to the old address; texts and terms are lost.
- With part C, payers on a dark device see the payment page light until they choose dark or system, like the rest of the product. Nothing needs deploying besides the new page and scripts; rolling it back returns the one-column page that follows the device.
- Deploying part B needs only the usual database update; rolling it back loses the uploaded merchant logos, and the payment pages show the merchant's name again.
