# ADR-0070: "Tierra firme" visual identity: forest green, warm neutrals and Geist

- **Status:** Accepted (by the project owner, 2026-11-05). Replaces the brand blue palette of [ADR-0030](0030-filament-theme-and-dark-mode-bridge.md) and the text face of [ADR-0042](0042-typography-mukta-geist-mono.md) (Mukta); Geist Mono for money stays. Amends the panel type scale of [ADR-0044](0044-panel-ux-theme-language-type-surfaces.md).
- **Date:** 2026-11-05
- **Source:** the owner's request to redesign the website and the Laravel panel from the "Tierra firme" design canvas; logos in `landing/assets/`.

## Context

The public landing and the README already use the new identity (logo: an L-shaped axis with an amber dot on a green tile). The Laravel UI (Blade pages and both Filament panels) still used the blue palette with Mukta. Every color and font lives in one token layer (`resources/css/tokens`, `theme.css`, `vite.config.js`), so the identity can change without touching views.

## Decision

- **Palette.** New primitive scales `forest` (primary, `#0f5c4c` at 600, mint `#5fc3a6` at 400 for dark) and `clay` (decorative amber `#e9a36a`). Neutrals become warm: sand (`#f6f4ef`) in light, charcoal (`#141311`) in dark. The `blue` scale stays, used only by `info`; `green` stays for `success`.
- **Semantics.** `primary`, `accent`, `link`, `focus-ring` and `brand-mark` map to `forest`. `accent` keeps its own token but shares primary's family (the brand has one green). `feature` (decorative only) becomes clay. `page` is sand and `surface` is white in light, so cards sit on a warm canvas.
- **Labels on fills.** `on-primary` and `on-accent` become theme-aware: white on the deep green in light, `forest-900` on the mint fill in dark (7.2:1 and above). Error fills keep a white label.
- **Brand mark.** The default panel/checkout mark is the logo glyph (L-shape plus dot) with the new token `brand-mark-dot`. Platform logo upload and display modes (ADR-0053) are unchanged.
- **Typography.** Text is **Geist** (400/500/600/700, latin, self-hosted from `@fontsource/geist`), replacing Mukta. Geist Mono stays for money (ADR-0042 rule unchanged). Stripe's card iframe loads Geist 400/500. The panel type scale returns near Filament's Inter-tuned defaults (sm 14px mobile / 15px desktop, base 16px) because Geist's x-height matches Inter.
- Filament's palettes are still built from the primitives (`DesignTokenPalette`): `primary` now reads the `forest` scale.

## Verification

Contrast measured for the pairs that carry text or control edges: light fg/page 16.9:1, fg-secondary 6.4:1, fg-muted 5.4:1, control edge 3.7:1, on-primary 7.9:1; dark fg/page 16.9:1, fg-secondary 8.8:1, fg-muted 5.2:1, control edge 3.2:1, on-primary 7.2:1, primary text 8.7:1. `pnpm run build` passes; `/design-system` and the panel sign-in render with the new tokens.

## Consequences

- One ADR changes the look; no view needed edits except the two brand-mark SVGs, the theme-color meta and the design-system preview.
- Merchant colors and logos on the payment page (ADR-0056) are untouched.
- `docs/frontend/*` reflect the new values; Stripe `Appearance` still reads colors from the rendered utilities, so the card form follows the palette with no hex of its own.
