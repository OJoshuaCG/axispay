# ADR-0037: Product naming: AxisPay internal name, configurable display name, `axp_` key prefix

- **Status:** Accepted (by the project owner, 2026-09-24). Supersedes [ADR-0028](0028-product-naming.md).
- **Date:** 2026-09-24
- **Source:** master plan header and section 29, open question #10 (now partially resolved)

## Context

ADR-0028 kept the plan's working name "PayLink" (`paylink`, API key prefix `plk_`) in code and took the display name from `APP_NAME` (then "Cirox Payments"). Open question #10 asked for the final name, domains and key prefix before Phase 3, when API keys are first issued.

The project owner decided on 2026-09-24:

1. The internal / tool name is `axispay` in code and "AxisPay" in prose.
2. The API key prefix is `axp_` (`axp_test_…` / `axp_live_…`).
3. The public display name must be configurable without touching code or infrastructure.

Using `APP_NAME` as the display name is unsafe: Laravel derives the cache prefix, the Redis prefix and the default session cookie name from it (`config/cache.php`, `config/database.php`, `config/session.php`). Rebranding through `APP_NAME` would silently empty the cache, orphan Redis keys and log everyone out.

## Options considered

1. **Keep using `APP_NAME` as the display name.** Rebranding changes the prefixes above.
2. **Point `app.name` at a new variable.** Hides the problem: `APP_NAME` and `app.name` would mean different things.
3. **Fixed `APP_NAME`, separate display-name setting read through one helper.** Chosen.

## Decision

- Code, configuration keys, environment variables, database names and users, Docker names, Artisan commands, session keys and cookies use `axispay` / `AXISPAY_`: `config/axispay.php`, `AXISPAY_*_HOST`, `axispay:create-platform-admin`, `axispay_admin_session` / `axispay_app_session`, databases `axispay` / `axispay_testing`, users `axispay_app` / `axispay_migrator`, image entrypoint `axispay-entrypoint`.
- API keys use `axp_test_` / `axp_live_` (`config/axispay.php` → `api_key_prefix`, log redaction patterns, OpenAPI). Resource ID prefixes (`plink_`, `pay_`, `re_`, `evt_`, …) describe resources, not the product, and do not change.
- `APP_NAME` is a fixed internal value, `AxisPay`. It is not a branding setting.
- The public name comes from `AXISPAY_DISPLAY_NAME` (`config/axispay.php` → `display_name`, default "AxisPay"), read only through `App\Modules\Shared\Support\Brand::displayName()`. It is used by the Blade layout title, the welcome, invitation and design-system pages, the Filament panels' brand name and the 2FA (TOTP) issuer, the mail sender name (`MAIL_FROM_NAME` defaults to it) and the mail/notification templates (published under `resources/views/vendor/` only to replace `config('app.name')`).
- To rebrand: change `AXISPAY_DISPLAY_NAME`. Never change `APP_NAME`.
- Domains (rest of open question #10) remain pending.
- Per-tenant branding (plan section 18, Phase 8) is separate: surfaces that show the merchant's name will resolve it from the tenant, not from `Brand`.

## Rationale

The internal name is stable and appears where integrators and operators depend on it (key prefix, commands, database users). The display name is a business decision that may change, so it lives in one setting with no side effects. The rename happens before any API key is issued (Phase 3) and before launch, so it breaks no integrator.

## Consequences

- Local `.env` files must be updated by hand: rename `PAYLINK_*` to `AXISPAY_*`, set `APP_NAME=AxisPay`, add `AXISPAY_DISPLAY_NAME`, point `MAIL_FROM_NAME` at it and use the new database names and users.
- The local compose project is now `axispay`, with a new volume; the old `paylink` container and volume are not reused and can be removed with `docker compose -p paylink down -v`.
- The session cookies are renamed, so every open session ends once. Acceptable before launch.
- The 2FA issuer follows the display name. Authenticator entries created before a rebrand keep the old label; the codes still work.
- The published mail views must be kept in sync with the framework's on upgrades.
