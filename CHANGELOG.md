# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Panels and Blade pages default to the **light** theme (ADR-0044); a saved
  choice is kept, and "System" still follows the OS. The panels have a
  visible light/dark/system control on the sign-in pages and in the topbar
  (from 768px; below that, in the user menu).
- Panel type scale for Mukta: table cells, sidebar, labels and buttons are
  15px below 1024px and 16px from 1024px (were 14px), badges 13px (were 12px),
  base text 17px on desktop.
- Language switcher shows the codes EN / ES at every width, in a compact
  segmented control shared with the theme controls (36px tall with a mouse on
  large screens, 44px targets on touch). On the sign-in pages the controls
  are right-aligned.
- Panel surfaces: canvas, raised and sunken layers (new `canvas`, `raised`,
  `sunken` tokens), a bordered sidebar with a clearer active item, sunken
  table header rows, a soft background on the sign-in page, a brand tile
  next to the product name, semibold page titles.
- Panel copy and tables: sentence-case labels ("Crear cliente",
  "Administradores"), page titles that name the record, rows that open the
  view page (the "View" row action is gone), empty states, a two-column
  tenant profile section with a copyable monospace ID, and Spanish
  "Inicio" / "Inicie sesión" / "Iniciar sesión" instead of Filament's
  "Escritorio" / "Entre a su cuenta" / "Entrar".

- Invitations created by the platform (on tenant creation or later) are
  attributed to the acting platform admin in the audit log (before: the
  current guard, or `system` from the CLI) and now count against the
  per-tenant invitation limit, which moved to `InvitationThrottle`.

### Fixed

- Panels: keyboard focus on sidebar items, tabs and topbar buttons is a
  visible 2px outline (Filament showed only a faint background tint).
- Panels: the language switcher on the sign-in pages was centred instead of
  right-aligned.
- Panels: an empty table toolbar row above the search field on phones.
- Panels: `419 Page Expired` on the second Livewire request of a page (a
  second sign-in attempt after a wrong password, the 2FA set-up, any action
  after the first one). The shared panel middleware was registered as Livewire
  persistent middleware, so every Livewire update re-ran `EncryptCookies` and
  `StartSession` on Livewire's internal request: the already-decrypted session
  cookie failed to decrypt, the store switched to a new, never-saved session id,
  and the next request failed the CSRF check. The middleware is no longer
  persistent (Livewire's update route runs the `web` group, and Filament makes
  its own panel middleware persistent). Reproduced and verified with the
  production image, `web` and `all-in-one` roles, direct and behind a proxy.

### Added

- Tenant lifecycle in the admin panel (ADR-0043). **Edit** a tenant's profile
  (legal and display name, time zone, default language, support e-mail) from
  the list or the view page (`UpdateTenantProfile`, superadmin, not on closed
  tenants, audited as `tenant.updated` with the changed field names; the
  support e-mail value is never stored in the audit entry). **Invitations**
  tab on the tenant page: invite an owner to an existing tenant
  (`InviteTenantOwner`, through `InviteUser` in the tenant context, owner role
  only), **Resend** pending or expired invitations (`ResendInvitation`: new
  token and 72-hour expiry, the old link returns 410 at once;
  `invitation.resent`) and **Revoke** pending ones (`RevokeInvitation`).
  Read-only **Users** tab (roles, active, 2FA yes/no, last sign-in). E-mails
  are masked for `support_readonly` (plan 17.4). New policy abilities
  `update`, `viewMembers`, `sendInvitations`, `revokeInvitations`. Tenants
  cannot be deleted (audit log FK `RESTRICT`); the view page says so next to
  the status.
- `php artisan axispay:mail-test {email} {--queue}`: sends a test e-mail now
  (or through the queue) and prints the mail settings without the password;
  exits 1 with the transport error on failure. Outgoing-mail section (cPanel
  example with `MAIL_SCHEME=smtps`) and "invitation not received"
  troubleshooting in both Dokploy guides.
- Production account recovery (ADR-0041): `php artisan axispay:reset-2fa` and
  `php artisan axispay:reset-password` for platform admins and tenant users, in
  every environment. Mandatory reason (stored in the audit log as
  `two_factor.reset` / `password.reset`, actor `system`), confirmation
  (default No; `--force` only with `--reason`), `--type` when the e-mail
  exists in both tables, tenant lookup through the audited platform context.
  Both revoke every session of the account, rotate its "remember me" token and
  clear its per-account sign-in throttle. The password is only read twice from
  a hidden prompt and follows `Password::defaults()`. New `ResetTwoFactor` and
  `ResetPassword` Actions; `axispay:dev-reset-2fa` now uses `ResetTwoFactor`.
  Account recovery runbook and troubleshooting rows (lost authenticator,
  forgotten password, throttling, clock skew, `APP_KEY` rotation) in both
  Dokploy guides.
- `php artisan axispay:doctor`: read-only deployment diagnostics (session and
  cookie configuration per host, session table, cache, trusted proxies, queue,
  pending migrations, container role, non-reversible `APP_KEY` fingerprint);
  exits non-zero on an error. Troubleshooting rows for `419` in both Dokploy
  guides.
- Panel session resilience (ADR-0040): a Livewire `419` reloads the page
  instead of showing Livewire's untranslated prompt (loop guard: once a minute
  per tab), and `GET /session/ping` (admin and app hosts, `204`, `no-store`,
  throttled) keeps the session of a visible, actively used tab alive.

- `all-in-one` container role for staging and local/test Dokploy servers
  (ADR-0039): supervisord runs php-fpm, nginx, `worker-critical`,
  `worker-default` and the scheduler as `www-data` in one container, with
  graceful stop (workers first, web last) and a health check that requires
  `/up` plus every worker and the scheduler. The `worker` role and the
  all-in-one workers build `queue:work` from one entrypoint function.
  New guide `docs/deployment/dokploy-all-in-one.md`, including a local test
  deployment without TLS (`.test` hosts, `SESSION_SECURE_COOKIE=false`,
  optional mkcert). Convention: every scheduled task uses
  `withoutOverlapping()` and `onOneServer()`. Production keeps four
  Applications (ADR-0036).
- ADR-0038 (decision only, implementation in Phases 4 and 8): the platform
  logo is managed from the superadmin panel, and the checkout always shows
  the platform in its footer ("Powered by" + logo and name) with the
  merchant's brand in the header; `show_platform_badge` becomes
  `platform_badge_style` (`standard` / `subtle`) and can no longer hide it.
- Production deployment (ADR-0035, ADR-0036):
  - Multi-stage `Dockerfile` (PHP 8.4 FPM + Nginx, Composer `--no-dev` with
    `check-platform-reqs`, pnpm + Vite build, non-root, no `.env`) and
    `.dockerignore`. One image, roles `web`, `worker`, `scheduler`, `release`
    and `artisan` selected by `CONTAINER_ROLE` or the first argument
    (`docker/app/entrypoint.sh`); role-aware `HEALTHCHECK`; runtime caches
    built at container start; migrations with the migrator connection before
    the web role serves (`RUN_MIGRATIONS=true`), other roles wait for them.
  - `config/trustedproxy.php` (`TRUSTED_PROXIES`) for TLS termination at a
    reverse proxy; security headers (HSTS, nosniff, frame options, referrer
    and permissions policies) in the image's Nginx.
  - `docs/deployment/dokploy.md`: step-by-step Dokploy guide (environments,
    variables, domains, Swarm health check and update settings, rollbacks,
    security checklist, troubleshooting).
  - Project documentation: `README.md`, `docs/README.md` (index) and
    `docs/architecture.md`.
- Phase 1 tenancy, identity and access (master plan section 27):
  - `Tenancy` module: `tenants` table and `TenantStatus` (plan 21.3) with
    `CreateTenant` / `ChangeTenantStatus` actions (superadmin only, reason,
    double confirmation to close, audited, owners notified); scoped
    `TenantContext` with audited `runAsPlatform()` and `runAsTenant()`;
    fail-closed `BelongsToTenant` and `BelongsToMode`; `TenantAware` jobs with
    `RestoreTenantContext`; tenant panel resolution and session test/live
    selector (`SwitchLivemode`, audited); `TenantSettings` DTO;
    `config/tenancy.php` as the single list of tenant tables.
  - Custom PHPStan rule (`Tests\PHPStan\Rules\TenantTableAccessRule`) against
    `DB::table()` / raw SQL on tenant tables and non-whitelisted
    `withoutGlobalScope(s)`, with rule tests.
  - Isolation test infrastructure: `assertTenantIsolation()`, a resource
    dataset, a route coverage test and a tenant-model inventory test.
  - `Identity` module: ULID tenant users (one tenant per user, global e-mail
    uniqueness), sign-in on the app host, TOTP 2FA with recovery codes
    (Filament MFA, encrypted, audited), invitations (signed, 72 h, single-use),
    re-authentication (password or 2FA code, 10 minutes), user deactivation.
  - `Access` module: spatie/laravel-permission 8 with teams (team = tenant),
    permission catalog and system roles seeders, permission-only policies,
    role changes with re-authentication, last-owner and no-escalation rules,
    sensitive-role notifications.
  - `PlatformAdmin` module: `platform_admins` and the `platform` guard on the
    admin host with mandatory 2FA, audited cross-host impersonation (read-only,
    30 minutes, banner), `axispay:create-platform-admin` command.
  - `Audit` module: append-only `audit_logs` (model guards and database
    triggers), redacted details, request metadata; records sign-ins, failed
    sign-ins, 2FA changes, invitations, role changes, tenant creation and status
    changes, impersonation and platform-context entries.
  - Filament 5.8 panels `admin` and `app`, themed from the design tokens
    (palettes built from `primitives.css`, self-hosted Jost, dark-mode bridge),
    EN/ES with a language switcher; resources: tenants, platform admins, audit
    log (admin); users, roles, audit log, profile (app).
  - `DevelopmentSeeder` (local only) and ADR-0030 to ADR-0033.
  - Security hardening after review (ADR-0034): shared `RoleGrantGuard` for
    role changes, invitations (re-checked on acceptance) and
    deactivation/reactivation; re-authentication for sensitive invitations and
    (de)activation; tenant row lock for owner invariants; per-host session
    cookies with a boot guard against a shared `SESSION_DOMAIN`; wider PHPStan
    tenancy rule; impersonation re-validates the platform admin on every
    request; per-account login throttling; per-tenant invitation throttling
    with audited refusals; local-only `axispay:dev-reset-2fa`.
- Phase 0 foundations (master plan section 27):
  - Tooling: Pest 5 (on PHPUnit 13), Larastan 3 at PHPStan level max, Pint with the
    Laravel preset plus `declare_strict_types` and strict comparison rules, and the
    Composer scripts `test`, `analyse`, `format`, `format:check` and `ci`.
  - Local MariaDB 11.8 LTS (`mariadb:11.8.9`) in `compose.yaml` with `utf8mb4` /
    `utf8mb4_uca1400_ai_ci`, strict `sql_mode`, UTC and InnoDB; `axispay` and
    `axispay_testing` databases; `axispay_app` and `axispay_migrator` users.
  - `mariadb` connection with explicit charset, collation, `sql_mode`, time zone,
    engine and optional TLS; `mariadb_migrator` connection for deploy-time migrations.
  - `app/Modules` structure with the `Shared` module: money value objects on
    brick/money (string parsing, minor units, HALF_UP rounding, DECIMAL(18,6)
    exchange rates), prefixed ULID identifiers, ULID model traits and schema
    macros for `ascii_bin` columns, API error envelope and `ApiErrorCode` catalog,
    `Request-Id` middleware.
  - Structured JSON log channel and secret/PII redaction on every log channel.
  - `sentry/sentry-laravel`, inert until `SENTRY_LARAVEL_DSN` is set, with an event
    scrubber that reuses the log redactor (Sentry or GlitchTip).
  - ADR-0001 to ADR-0024 from the master plan, and ADR-0025 to ADR-0029.
  - OpenAPI 3.1 skeleton (`docs/api/openapi.yaml`), `docs/development.md`, and a
    GitHub Actions CI workflow on PHP 8.4 and 8.5 with a MariaDB 11.8 service.

### Changed

- Typography (ADR-0042): Mukta replaces Jost as the text face (Blade pages and
  both Filament panels), and Geist Mono is the face for currency and numeric
  money data, exposed as the token `--font-numeric` / utility `font-numeric`.
  The `amount` utility (and so `<x-amount>` and `class="amount"` inputs) now
  sets the numeric face; Filament money columns use
  `->fontFamily(FontFamily::Mono)`, mapped to `--font-numeric` in the panel
  theme. `--font-mono` (code) is Geist Mono too. Both faces are self-hosted from
  `@fontsource/mukta` and `@fontsource/geist-mono` (latin subset; Mukta
  400/500/600/700 with 400 and 600 preloaded and a fontaine fallback, Geist
  Mono 400/600 without preload); `@fontsource/jost` is removed. Fonts are now
  loaded through the plugin's `local()` provider on the packages' WOFF2 files:
  with `fontsource()`, browsers downloaded each weight twice (woff2 preload
  unused, woff applied). The
  `/design-system` typography section shows both faces.
- Renamed the product to AxisPay (ADR-0037, supersedes ADR-0028): internal
  name `axispay` replaces the working name `paylink` in `config/axispay.php`,
  `AXISPAY_*` environment variables, `axispay:*` Artisan commands, session keys
  and cookies (`axispay_admin_session`, `axispay_app_session`), database names
  and users (`axispay`, `axispay_testing`, `axispay_app`, `axispay_migrator`),
  the compose project and the image (`axispay-entrypoint`,
  `axispay-healthcheck`). API keys use the `axp_test_` / `axp_live_` prefix.
- The public display name is configurable with `AXISPAY_DISPLAY_NAME`
  (default "AxisPay", read through `Brand::displayName()`) for pages, panels,
  the 2FA issuer, the mail sender and mail templates. `APP_NAME` is now a fixed
  internal value (`AxisPay`) because it drives the cache, Redis and session
  prefixes.
- pnpm is the only package manager: its version is pinned in
  `package.json` → `packageManager` (used by CI and the Dockerfile), and the
  `composer setup` script uses pnpm instead of npm.
- Production logging guidance: the container logs redacted JSON to stderr
  (`LOG_CHANNEL=stderr` with the JSON formatter) instead of rotated files.
- The default `users` migration now creates tenants and tenant users with ULID
  keys and DATETIME(6); `sessions.user_id` is a `char(26)` ULID.
- `App\Models\User` moved to `App\Modules\Identity\Models\User`.
- `<x-language-switcher>` accepts an optional `redirect` path.
- `.atl/` and Filament's published assets are gitignored; `filament:upgrade`
  runs on `post-autoload-dump`.
- Minimum PHP version raised from 8.3 to 8.4 (required by Pest 5; the plan prefers 8.4).
- Tests run against MariaDB instead of in-memory SQLite.
- `declare(strict_types=1);` added to every PHP file.
- `rules.md` now points to `docs/plans/master.md`.
