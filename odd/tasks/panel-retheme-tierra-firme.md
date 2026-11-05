# Panel re-theme: "Tierra firme"
Objective: move the Laravel UI (Blade + Filament panels) to the Tierra firme identity (forest green + amber, warm neutrals, Geist). Branch: main (no commits unless asked). Test runner: Pest (no DB here) + `pnpm run build`.

## Specs
- S1: "adaptarlo a la nueva propuesta" — tokens follow the artifact: forest `#0F5C4C`, amber `#E9A36A`, canvas `#F6F4EF`, dark canvas `#141311`.
- S2: Logo is the L-glyph + dot tile from `landing/assets/` ("la parte de configuracion de logos y demas, puede permanecer igual en un panel admin").
- S3: Text face Geist (replaces Mukta); Geist Mono stays for money.
- S4: Everything stays token-driven: values change only in their home files (`docs/frontend/README.md`); a new ADR records the change.

## Tasks
- [x] T1 (S1,S2) color primitives, semantic tokens, brand mark, theme-color meta — inline
- [x] T2 (S3) Mukta -> Geist: vite fonts, Stripe fonts, panel type scale, tests — inline
- [x] T3 (S4) ADR, docs/frontend, design-system preview, CHANGELOG — inline
- [x] T4 build + checks — inline

## Log
L1 (2026-11-05): "Podrias apoyarme a redisenias el panel laravel? Eso estaria mas genial. Podrias apoyarme con ello? seria de mucha ayuda comenzar a diseniarlo y adaptarlo a la nueva propuesta"
