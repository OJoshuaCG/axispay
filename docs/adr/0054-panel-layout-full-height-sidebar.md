# ADR-0054: Panel layout: full-height sidebar with the platform logo

- **Status:** Accepted (by the project owner, 2026-09-29)
- **Date:** 2026-09-29
- **Source:** owner request of 2026-09-29; [ADR-0053](0053-platform-logo-brought-forward.md) (platform logo); [ADR-0044](0044-panel-ux-theme-language-type-surfaces.md) (panel UX); [ADR-0025](0025-filament-panels.md) (panels).

## Context

With the platform logo in place (ADR-0053), the owner found it too small: in both panels it was a thin strip in the top bar, the same size on the sign-in page as in the top bar, and on large screens the menu started under a full-width top bar, so the logo never had room.

## Decision

**Sign-in pages** (sign-in, two-factor set-up and challenge, invitation acceptance; both panels):

- the logo can grow up to 320 px wide on large screens and up to the card's width on phones, and at most 120 px high (the height every uploaded logo is reduced to), keeping its proportions;
- in "logo and name" the name is shown centered under the logo;
- without a logo (or in "name only") the stand-in mark and the name are shown larger than in the top bar.

**Screens of 1024 px and wider** (both panels):

- the notices that span the whole window (for example "you are viewing this account as support staff", or the account status notices) stay on top, full width;
- the menu becomes a **sidebar that runs the full height of the window** on the left, with its own scroll, and shows the platform logo at the top (up to the sidebar's width and about 72 px high; in "logo and name" the name under it);
- the top bar sits to the right of the sidebar and keeps its controls (test/live mode, language, theme, user menu); it no longer shows the logo;
- the page content sits below the top bar, to the right of the sidebar.

**Phones and tablets (below 1024 px): no change.** The menu stays a drawer opened from the top bar, and the top bar spans the width.

**Sizes are adjustable in one place.** The logo sizes (sign-in pages, sidebar, top bar) are design tokens; the owner can ask for a different size without touching the pages.

**Accessibility is unchanged.** The order of the page for keyboards and screen readers is the same as before (skip link, top bar, menu, content); only the visual placement changes.

## Consequences

- **Risk: the layout depends on the panel framework's internal names.** The panels are built on Filament, whose page structure puts the top bar before the menu and the content. The new layout is achieved without changing that structure, by styling Filament's own elements (their internal class names and size variables). A Filament upgrade that renames them would bring back the old layout (or a broken one) without any error. Mitigation: the styling is in one clearly marked block, the automated tests check the page structure it relies on, and every Filament upgrade must include a visual check of both panels at 1024 and 1440 px, with a notice banner visible.
- Pop-up windows, side panels, notifications and menus are not affected: they float over the page.
- Because the sidebar starts below the notices and is as tall as the window, its bottom is hidden by the notices' height until the page is scrolled; it has its own scroll.
- The favicon is added in the same round as an amendment of ADR-0053.
