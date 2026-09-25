# ADR-0042: Typography: Mukta for text, Geist Mono for currency and numeric data

- **Status:** Accepted (by the project owner, 2026-09-25)
- **Date:** 2026-09-25
- **Source:** project owner decision; `docs/frontend/tokens.md`, `payments-ui.md`; [ADR-0025](0025-filament-panels.md), [ADR-0030](0030-filament-theme-and-dark-mode-bridge.md)

## Context

The design system used Jost for all text, with tabular figures (`tnum`) for amounts. The project owner asked for a different base face and for money to be visually distinct and strictly aligned: amounts appear in checkout summaries, dashboards, tables and inputs, often in columns.

Requirements for the text face: the four design weights (400, 500, 600, 700), Latin coverage for English and Spanish, self-hosting from a pinned npm package (`@fontsource/*`, no font CDN), and a metric-matched fallback. For the numeric face: equal-width digits, U+2212 MINUS SIGN (used by `<x-amount>`), `€`, and the same self-hosting.

Verified in the published packages (`@fontsource/mukta` 5.3.0, `@fontsource/geist-mono` 5.3.0): Mukta ships 200–800 in latin, latin-ext and devanagari; Geist Mono ships 100–900; both latin subsets cover U+0000–00FF, `€` and U+2212. Geist Mono digits are all 600 units wide; it has no `zero` (slashed zero) feature.

## Options considered

1. **Lato** for text. Rejected: no 500 or 600 weights, and the design uses both (`font-medium`, `font-semibold`).
2. **Geist (sans)** for numbers, with `tabular-nums`. Considered: proportional letters, tabular digits by feature.
3. **Geist Mono** for numbers. Every glyph has the same width, so digits, separators and currency codes align without relying on an OpenType feature.
4. **Mukta** for text. Has 400/500/600/700 and a Latin subset of about 20 KB per weight (woff2).

## Decision

- **Text: Mukta** (`--font-sans`, utility `font-sans`, the default), weights 400/500/600/700, latin subset.
- **Currency and numeric money data: Geist Mono** (option 3, chosen by the project owner for alignment), exposed as the token `--font-numeric` and the utility `font-numeric`, weights 400 and 600, latin subset.
- **Rule: every currency amount uses `<x-amount>`, the `amount` utility, or `font-numeric`.** The `amount` utility now sets the font family as well as `tabular-nums slashed-zero`; `<x-amount>` applies it, amount inputs use `class="amount"` (no separate component prop), and Filament money columns use `->fontFamily(FontFamily::Mono)`, which the panel theme maps to `--font-numeric`.
- `--font-mono` (code, keys, IDs) is also Geist Mono, but stays a separate token: code styling can change without touching money, and vice versa.
- Loading (`vite.config.js`, `laravel-vite-plugin` fonts): one latin WOFF2 file per weight from the pinned packages, through the plugin's `local()` provider (its `fontsource()` provider, in 3.2.0, emits woff2 and woff as two identical-descriptor rules; the woff wins and browsers downloaded both, leaving the woff2 preloads unused); `display: swap`; preload Mukta 400 and 600 only. Mukta keeps the fontaine metric-matched fallback. Geist Mono has it off: fontaine 0.8 does not detect it as monospace and would emit a scaled Arial in front of the monospace stack; the system monospace fallbacks already match its 0.6em advance.

## Rationale

A monospace face makes alignment a property of the font instead of a feature that every fallback must also support, and it separates money from prose at a glance. Mukta covers every design weight, so the weight tokens and components did not change. Self-hosting from pinned packages keeps the no-CDN rule of ADR-0030.

## Consequences

- New token `--font-numeric` / utility `font-numeric`. The design weights, type scale and line heights are unchanged (every size sets an explicit line height).
- Geist Mono is wider than Mukta: about 0.6em per character. Amounts stay `whitespace-nowrap` in `minmax(0,1fr)_auto` layouts so labels wrap instead (checked at 320px on `/design-system`).
- On amounts, `font-medium` renders 400 and `font-bold` renders 600 (only two Geist Mono weights are loaded). Load another weight in `vite.config.js` if a design needs it.
- Slashed zero is still not available in the primary face; do not rely on it.
- Font payload: Mukta about 20–22 KB per weight, Geist Mono about 10 KB per weight (woff2, latin). Two preloads (Mukta 400 and 600), as with Jost.
