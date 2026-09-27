# Local development

This guide gets the application running locally with the same database the
tests and CI use. Read `rules.md` and `docs/plans/master.md` before changing
code, and `docs/frontend/README.md` before touching UI. For production see
[deployment/dokploy.md](deployment/dokploy.md); for how the pieces fit, see
[architecture.md](architecture.md).

## Requirements

- PHP 8.4 or 8.5 with `pdo_mysql`, `intl` and `mbstring`.
- Composer 2.
- Node.js 22 with pnpm; the version is pinned in `package.json` →
  `packageManager` (`corepack enable` picks it up). pnpm is the only package
  manager: the repository ships `pnpm-lock.yaml` and no `package-lock.json`.
- Docker with Docker Compose.

## First-time setup

1. Start MariaDB 11.8 (host port 33061 by default):

   ```sh
   docker compose up -d
   docker compose ps   # wait until the mariadb service is "healthy"
   ```

   On the first start, `docker/mariadb/initdb.d/01-databases-and-users.sql`
   creates the `axispay` and `axispay_testing` databases and the `axispay_app`
   and `axispay_migrator` users. Init scripts only run on an empty volume; to
   rerun them, remove the volume with `docker compose down -v` (this deletes
   local data).

   Before the AxisPay rename (ADR-0037) the compose project was called
   `paylink`. Its container and volume are not reused: stop them with
   `docker compose -p paylink stop` and, once nothing there is needed,
   remove them with `docker compose -p paylink down -v`.

2. Install dependencies and create `.env`:

   ```sh
   composer install
   cp .env.example .env
   php artisan key:generate
   pnpm install
   pnpm run build
   ```

3. Check the database keys in `.env` (see below; `DB_CONNECTION` must be
   `mariadb`), migrate and seed the permission catalog:

   ```sh
   php artisan migrate --seed
   php artisan db:seed --class=DevelopmentSeeder   # local demo accounts, APP_ENV=local only
   ```

4. Run the app and the asset server:

   ```sh
   pnpm run dev
   php artisan serve
   ```

## Environment keys

| Key | Local value | Notes |
|---|---|---|
| `APP_NAME` | `AxisPay` | Fixed internal name (cache, Redis and session prefixes). Do not change it to rebrand (ADR-0037). |
| `AXISPAY_DISPLAY_NAME` | `AxisPay` | Public name shown in pages, panels, the 2FA issuer and the mail sender. Change this one to rebrand. |
| `DB_CONNECTION` | `mariadb` | Only MariaDB is supported. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `33061` | Production: the external database server. |
| `DB_DATABASE` | `axispay` | Tests use `axispay_testing` (set in `phpunit.xml`). |
| `DB_USERNAME` / `DB_PASSWORD` | `axispay_app` / `axispay_app` | Runtime user (DML only in production). |
| `DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD` | `axispay_migrator` / `axispay_migrator` | Used by `--database=mariadb_migrator`. |
| `MYSQL_ATTR_SSL_CA`, `MYSQL_ATTR_SSL_CERT`, `MYSQL_ATTR_SSL_KEY`, `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` | unset | Optional TLS to an external server. |
| `AXISPAY_API_HOST`, `AXISPAY_APP_HOST`, `AXISPAY_ADMIN_HOST`, `AXISPAY_PAY_HOST` | `*.localhost` | Surface hosts (ADR-0027). |
| `AXISPAY_PASSWORD_CHECK_UNCOMPROMISED` | `true` (default) | Breached-password check on new passwords (HIBP). Off in `phpunit.xml`. |
| `LOG_STACK` | `single` | The production image logs redacted JSON to stderr instead (ADR-0035). |
| `TRUSTED_PROXIES` | unset | Production behind Traefik: its network range (`config/trustedproxy.php`). |
| `SENTRY_LARAVEL_DSN` | empty | Error tracking stays off while empty (ADR-0029). |
| `STRIPE_TEST_SECRET` / `STRIPE_LIVE_SECRET` | empty | Platform secret key per mode (`sk_test_…` / `sk_live_…`); a key of the wrong mode is refused. Never mix modes (ADR-0047). |
| `STRIPE_TEST_PUBLISHABLE` / `STRIPE_LIVE_PUBLISHABLE` | empty | Platform publishable key per mode (checkout, Phase 4). |
| `STRIPE_TEST_CONNECT_WEBHOOK_SECRET` / `STRIPE_LIVE_CONNECT_WEBHOOK_SECRET` | empty | `whsec_…` of the Connect webhook endpoint of each mode (see "Stripe" below). |
| `AXISPAY_STRIPE_ALLOWED_COUNTRIES` | `MX` | Countries a connected account may be in (comma-separated; the first is the default). |
| `AXISPAY_STRIPE_PLATFORM_ONBOARDING` / `AXISPAY_STRIPE_API_KEY_CONNECTIONS` | `true` | Connection methods offered in the tenant panel. OAuth stays off until Phase 4B. |
| `AXISPAY_STRIPE_WEBHOOK_BASE_URL` | empty | Base URL of the webhook endpoint created on a merchant account (api_key). Empty: `https://<API host>`. Locally: a public tunnel URL. |
| `GATEWAY_CREDENTIALS_KEY` | generate | Dedicated key for merchant Stripe credentials, **not** `APP_KEY`: `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`. Back it up separately from `APP_KEY` and from database backups. |
| `GATEWAY_CREDENTIALS_KEY_VERSION` / `GATEWAY_CREDENTIALS_PREVIOUS_KEYS` | `1` / empty | Rotation: see "Rotating GATEWAY_CREDENTIALS_KEY". |
| `AXISPAY_PAY_BASE_URL` | `http://pay.localhost:8000` | Base of the public link URL (`<base>/l/<token>`). Empty: `https://<pay host>`. |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | Cloudflare test keys (below) | Bot check of the checkout after a decline (plan 11.7). Required in production; without the secret, a payment that needs the check is refused. |
| `AXISPAY_CHECKOUT_SANDBOX` | `false` | `true` only with `APP_ENV=local` or `testing`: fake gateway + Stripe.js stub (ADR-0051). The application refuses to boot with it in any other environment. |
| `AXISPAY_MAX_CHARGE_USD_MINOR` / `AXISPAY_MAX_CHARGE_MXN_MINOR` | `1000000` / `20000000` | Platform maximum per link in cents (USD 10,000.00 / MXN 200,000.00, ADR-0048). The minimums follow Stripe and are not variables. |
| `AXISPAY_API_RATE_LIMIT_LIVE` / `AXISPAY_API_RATE_LIMIT_TEST` | `100` / `100` | API requests per minute per key (ADR-0048). |
| `AXISPAY_API_FAILED_AUTH_PER_MINUTE` | `30` | Failed API authentications per IP and minute before that IP gets `429` (ADR-0048). Locally, `php artisan cache:clear` lifts a lock. |

The local credentials are defined in `compose.yaml` and the init script. They
are not secrets and must never be reused outside Docker.

The public API answers on the API host only, for example
`http://api.localhost:8000/v1/...`. Browsers resolve `*.localhost` to the
loopback address without extra configuration.

## Panels (Phase 1)

| Panel | Local URL | Guard | Who |
|---|---|---|---|
| Platform (`admin`) | `http://admin.localhost:8000` | `platform` | Platform admins (`platform_admins` table) |
| Tenant (`app`) | `http://app.localhost:8000` | `web` | Tenant users (`users` table) |

Browsers resolve `*.localhost` to 127.0.0.1; for `curl`, use
`--resolve app.localhost:8000:127.0.0.1` or a `Host` header. The two hosts
never share a session cookie.

### Local accounts (DevelopmentSeeder)

`php artisan db:seed --class=DevelopmentSeeder` refuses to run unless
`APP_ENV=local`. It prints and creates:

| Panel | E-mail | Password | Role |
|---|---|---|---|
| admin | `superadmin@axispay.test` | `local-dev-password` | Superadmin |
| app | `owner@demo.axispay.test` | `local-dev-password` | Owner of "Demo Company" (active) |

These are local-only, non-secret credentials. Never create them anywhere else.

### Two-factor authentication

- Mandatory for every platform admin and for tenant users with a sensitive
  permission (owner, admin, integration manager, finance). On first sign-in
  they are sent to the 2FA set-up page: scan the QR code with an authenticator
  app (TOTP) and store the recovery codes.
- Other users can enable it from **Profile**.
- Sensitive actions (e.g. changing roles) ask for the password or a current
  2FA code again; the confirmation lasts 10 minutes.

### Other flows

- **Invitations:** Users → Invite user. With `MAIL_MAILER=log`, the signed link
  is written to `storage/logs/laravel.log`; open it on the app host.
- **Tenants in the admin panel** (ADR-0043), superadmin only unless noted:
  - **Create** needs an owner e-mail (ADR-0045): the owner gets an invitation
    (always the owner role; the owner invites everyone else from the tenant
    panel). An address that already belongs to a user is refused: e-mails are
    unique and a user belongs to one tenant, so use another address.
  - **Edit** (list row or view page): legal and display name, time zone,
    default language, support e-mail. Not on a closed tenant. The status is
    changed only with **Change status** (reason; closing asks to retype the
    display name).
  - **Invitations** tab: e-mail, role, status (pending, accepted, expired,
    revoked), invited and expiry dates. **Resend** (pending or expired) issues a
    new link valid for 72 hours and kills the old one; **Revoke** (pending)
    kills the link. Both count against the per-tenant invitation limit (resend)
    and are audited.
  - **Users** tab: roles, active, 2FA yes/no, last sign-in. `support_readonly`
    admins see e-mails masked. **Make owner** (superadmin, tenant not closed,
    active users who are not owners yet): adds the owner role and keeps the
    other roles; asks for a reason (10+ characters) and your password or 2FA
    code; audited as `owner.promoted` in the platform and tenant logs; the
    tenant's owners and the user get an e-mail (queued).
  - **Owner** column on the list (Active / Pending invitation / None) and a
    **No active owner** filter. "Active" means a user who is not deactivated
    holds the owner role. The view page warns while there is no active owner,
    shows the owner invitation's expiry, and offers **Invite owner** and
    **Resend owner invitation**.
  - **Recovering ownership:** if the person has no account, invite (or resend)
    from the warning. If they already are a user of that tenant, use **Make
    owner**. If their account is deactivated and no owner is left, reactivate
    it first with the account recovery CLI (ADR-0041).
  - Tenants are never deleted (the audit log references them): close them.
- **Mail check:** `php artisan axispay:mail-test you@example.com` sends a test
  message now through the default mailer and prints mailer, host, port, scheme,
  username and sender (never the password); on a transport error it prints the
  exception and exits 1. `--queue` queues it like the invitations, to also check
  the worker. With `MAIL_MAILER=log` the message goes to the log at debug level:
  locally to `storage/logs/laravel.log`; in the production image only when
  `LOG_LEVEL=debug` (the image defaults to `info`).
- **Impersonation:** admin panel → Tenants → a tenant → View as user (reason
  required, read-only, 30 minutes, audited). A banner in the tenant panel stops it.
- **Test/live selector:** the badge in the tenant panel topbar (test by default).
- **Reset 2FA of a local account** (lost authenticator, fresh seed):
  `php artisan axispay:dev-reset-2fa owner@demo.axispay.test` (works for tenant
  users and platform admins; no prompts; refuses to run unless `APP_ENV=local`;
  audited through the same `ResetTwoFactor` Action as the production command).
  The next sign-in asks to set 2FA up again.
- **Account recovery in any environment** (ADR-0041), for platform admins and
  tenant users. Both commands show the account, ask for a reason (10 to 500
  characters, stored in the audit log, never a secret) and a confirmation
  (default No), sign the account out everywhere (sessions and "remember me")
  and clear its sign-in throttle on both panels:
  - `php artisan axispay:reset-2fa <email> [--type=platform|tenant] [--reason=…] [--force]`
    removes the 2FA secret and recovery codes (`two_factor.reset`). An account
    without 2FA is a no-op. `--force` skips the confirmation only together
    with `--reason`; without a terminal both are required.
  - `php artisan axispay:reset-password <email> [--type=platform|tenant] [--reason=…]`
    reads the new password twice from a hidden prompt (never an argument or
    option; refuses `--no-interaction` and a missing terminal), applies
    `Password::defaults()` and records `password.reset` without the password.
  - `--type` is required when the e-mail belongs to both a platform admin and a
    tenant user. Exit code `0` on success or no-op, `1` otherwise. In a
    container use `docker exec -it`. Runbook: [deployment/dokploy.md](deployment/dokploy.md#account-recovery).
- **Sign-in throttling:** 5 failed attempts per account in 15 minutes lock that
  account's sign-in for the rest of the window (plus Filament's per-IP limit).
  Locally, clear it with `php artisan cache:clear`; both recovery commands
  clear it for the account they recover.
- **Session cookies:** each host has its own (`axispay_admin_session`,
  `axispay_app_session`); keep `SESSION_DOMAIN` empty or the app will not boot.
- **Platform admins in other environments:** `php artisan axispay:create-platform-admin`
  (interactive; the password is never passed as an argument).
- **Deployment diagnostics:** `php artisan axispay:doctor` (read-only, safe in
  production, prints no secret). It checks `APP_ENV`/`APP_DEBUG`, the `APP_URL`
  scheme against `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN`, the surface hosts
  and the session cookie each panel host uses, the session table, the cache,
  `TRUSTED_PROXIES`, the queue connection and pending migrations. It also shows
  the container hostname and role and an `APP_KEY` fingerprint (the first 12
  hex characters of its SHA-256, not reversible) to compare across containers.
  It exits non-zero when a check fails.
- **Panel session resilience (ADR-0040):** a Livewire `419` reloads the page
  (at most once a minute per tab, then Livewire's prompt), and an open panel tab
  pings `GET /session/ping` every 5 minutes while it is visible and the person
  has interacted with it.
- **Panel middleware is not Livewire-persistent.** Livewire's update route
  already runs the `web` group. Never pass `isPersistent: true` for cookie,
  session or CSRF middleware: re-running them on Livewire requests switches
  the session and causes a `419` on the next request
  (`tests/Feature/Panels/LivewireSessionTest.php`).

## Public API (Phase 3, ADR-0048)

The API answers on the API host under `/v1` (locally
`http://api.localhost:8000/v1`). The contract is `docs/api/openapi.yaml`.

### Getting a key locally

1. Sign in to the tenant panel as the demo owner and pick the mode with the
   test/live badge (test by default).
2. **Settings → Stripe connection** must show a connection that can charge in
   that mode: links cannot be created without one (`gateway_not_ready`).
3. **Settings → API keys → Create API key**: a name and the permissions
   (`links:create`, `links:read`, `links:cancel` are preselected). Confirm your
   password or 2FA code. The key (`axp_test_…`) is shown **once**: copy it
   before closing the dialog. Revoke it from the same list.

A live key e-mails every owner of the tenant (with `MAIL_MAILER=log`, look in
`storage/logs/laravel.log`).

### Calling the API

Every request sends the key as a bearer token. Creating a link also needs an
`Idempotency-Key` (any unique string per intended link; a retry with the same
key and body returns the first answer). Amounts are decimal **strings**.

```sh
curl -s http://api.localhost:8000/v1/payment_links \
  -H "Authorization: Bearer axp_test_..." \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-A-1029" \
  -d '{"amount": "1500.00", "currency": "MXN", "description": "Order A-1029"}'
```

Scopes, filters, paging and error codes are in `docs/api/openapi.yaml`. Every
response to an authenticated key, errors included, carries `Request-Id` and
`RateLimit-*` headers. The panel lists the same links under **Payments → Payment
links**.

## Stripe (Phase 2, ADR-0047)

The tenant panel has **Settings → Stripe connection** (`/settings/stripe`, users
with `gateway:manage`; every change asks for the password or a 2FA code). Two
methods are offered, per mode (test and live connections are separate):

- **Create or connect with Stripe (recommended)**, `platform_onboarding`: the
  platform creates a Standard-equivalent connected account (MX by default) and
  sends the user to Stripe's hosted onboarding.
- **Advanced: use my API keys**, `api_key`: the tenant pastes a restricted key
  and a publishable key of its own Stripe account.

### Testing locally without Stripe

`composer test` never reaches Stripe: the gateway tests replace Stripe's HTTP
layer (`Tests\Support\FakeStripeHttpClient`), use `FakePaymentGateway` for the
domain, sign webhook fixtures (`tests/Fixtures/Stripe/<api version>/`) with
the secrets set in `phpunit.xml`, and set a dummy platform key. To exercise
the incoming webhooks by hand, sign a fixture with the Connect secret of your
`.env` and post it to the API host:

```sh
php -r '
require "vendor/autoload.php";
$body = file_get_contents("tests/Fixtures/Stripe/2026-08-26.dahlia/account.updated.json");
$body = strtr($body, ["{{event}}" => "evt_local_1", "{{account}}" => "acct_…", "\"{{livemode}}\"" => "false"]);
file_put_contents("/tmp/event.json", $body);
echo \Stripe\WebhookSignature::generateSignatureHeader($body, getenv("SECRET"));
' > /tmp/signature
curl -s -X POST http://api.localhost:8000/webhooks/stripe/connect/test \
  -H "Stripe-Signature: $(cat /tmp/signature)" -H 'Content-Type: application/json' \
  --data-binary @/tmp/event.json
```

(run with `SECRET=<your STRIPE_TEST_CONNECT_WEBHOOK_SECRET>`). The event is
stored in `provider_events` and processed by `ProcessProviderEventJob` on the
`critical` queue (`php artisan queue:work --queue=critical,default`), which
re-reads the account from Stripe, so a real test key is needed for that step.
With the Stripe CLI instead: `stripe listen --forward-connect-to
localhost/webhooks/stripe/connect/test` (see `rules.md`).

### Contract tests against Stripe test mode

`./vendor/bin/pest --group=stripe` runs only when the keys are **exported in
the shell** (they are never read from `.env`; `phpunit.xml` blanks them):

```sh
STRIPE_TEST_SECRET=sk_test_… \
STRIPE_CONTRACT_RESTRICTED_KEY=rk_test_… STRIPE_CONTRACT_PUBLISHABLE_KEY=pk_test_… \
STRIPE_CONTRACT_OTHER_PUBLISHABLE_KEY=pk_test_…   # optional: a pk of ANOTHER account
./vendor/bin/pest --group=stripe
```

They create and delete a test connected account and a test webhook endpoint,
and confirm the points ADR-0047 marks as unverified (PII-token check of pk ↔
rk, permission probes answered 403/400).

### Setting up the platform account (once per mode)

1. In the platform's Stripe Dashboard (the platform account is in Mexico),
   complete the Connect platform profile and the branding shown during
   onboarding.
2. Copy the secret and publishable keys of the mode into `STRIPE_<MODE>_SECRET`
   and `STRIPE_<MODE>_PUBLISHABLE`.
3. **Developers → Webhooks → Add destination**: events from **Connected
   accounts**, API version `2026-08-26.dahlia`, URL
   `https://<API host>/webhooks/stripe/connect/test` (test mode) or
   `…/connect/live` (live mode), events `account.updated` and
   `account.application.deauthorized` (Phase 4 adds the payment events).
   Copy its signing secret into `STRIPE_<MODE>_CONNECT_WEBHOOK_SECRET`.

### Connecting the team's own Stripe account with API keys (api_key)

The platform team is the first tenant (ADR-0047). In the Stripe account you
want to receive payments in, for each mode (start with test mode):

1. **Developers → API keys → Create restricted key** ("Building your own
   integration"). Name it, for example, `AxisPay`.
2. Grant exactly these permissions (Stripe names; the Dashboard groups them
   by resource) and leave everything else as **None**:

   | Resource | Access | Used for |
   |---|---|---|
   | PaymentIntents | Write | Charges (Phase 4) |
   | Charges and Refunds | Write | Refunds (Phase 7); includes reading charges |
   | Webhook Endpoints | Write | The endpoint the platform creates on your account |
   | Accounts (Core → Accounts) | Read | Country and charge status; daily health check |
   | Tokens | Read | Proving the publishable key belongs to the same account |
   | ConfirmationTokens | Read | Card checkout (deferred intents) |
   | PaymentMethods | Read | Card country (currency conversion) |
   | Disputes | Read | Disputes (Phase 7) |
   | Events | Read | Reconciliation |

   Do **not** grant Payouts, Transfers or Balance: the platform warns about
   them, and live mode asks for an extra confirmation. Never use a secret key
   (`sk_`): it is always refused.
3. Copy the restricted key (`rk_test_…`) and the publishable key (`pk_test_…`)
   of the **same account and mode**.
4. In the tenant panel, switch to the matching mode (test/live selector),
   open **Settings → Stripe connection → Advanced: use my API keys**, paste
   both keys, accept the risk notice and confirm your password or 2FA code.
5. The platform validates the keys (format, mode, account, country, pk ↔ rk,
   permissions), stores the restricted key encrypted with
   `GATEWAY_CREDENTIALS_KEY` and creates a webhook endpoint on your account
   (`https://<API host>/webhooks/stripe/direct/<connection id>`, API version
   pinned). Do not edit or delete that endpoint in Stripe. Only `rk_…last4`
   is shown afterwards.
6. To rotate: create a new restricted key in Stripe, use **Update keys**, then
   delete the old key in Stripe. To move to the recommended method later:
   **Disconnect** (the webhook endpoint is deleted and the keys erased), then
   **Create or connect with Stripe**.

The endpoint only receives `account.updated` in Phase 2 (the events the platform
handles now; no payer data of your other sales). When a later phase adds events
to `axispay.gateways.stripe.direct_webhook_events`, run
`php artisan axispay:stripe-sync-webhook-endpoints` once after the deploy: it
updates the events of every existing endpoint, no reconnection needed.

Locally, Stripe cannot reach `*.localhost`: set `AXISPAY_STRIPE_WEBHOOK_BASE_URL`
to a public tunnel pointing at the API host before connecting with keys.

### Rotating GATEWAY_CREDENTIALS_KEY

1. Move the current key to `GATEWAY_CREDENTIALS_PREVIOUS_KEYS` as
   `1:base64:…` (its version and value), set a new `GATEWAY_CREDENTIALS_KEY`
   and bump `GATEWAY_CREDENTIALS_KEY_VERSION` to `2`.
2. Deploy, then run `php artisan axispay:rotate-gateway-credentials-key`.
3. Remove the old key from `GATEWAY_CREDENTIALS_PREVIOUS_KEYS` once the
   command re-encrypted every connection.

## Checkout (Phase 4, ADR-0051)

The payment page lives on the pay host: `http://pay.localhost:8000/l/<token>` locally. It has its own anonymous session (cookie `axispay_pay_session`) for the CSRF token of the pay button.

### Running the checkout without Stripe keys (sandbox)

1. In `.env`: `APP_ENV=local`, `AXISPAY_CHECKOUT_SANDBOX=true`, and the Cloudflare test keys for Turnstile (below).
2. Build the assets (`pnpm run build`, or `pnpm run dev` while working on the page).
3. Create the demo tenant and links: `php artisan axispay:checkout:demo` prints one URL per state (active in Spanish and in English, all payer fields, expired, canceled). Run it again for fresh links.
4. Open a link. The card form is replaced by a **test card** picker:

| Test card | What happens |
|---|---|
| Approved | Authorized, then captured: "Payment complete" |
| Declined / Insufficient funds | Generic decline message; the next try asks for the security check |
| Bank verification (3D Secure) | A "sandbox bank" dialog: approve (paid) or fail verification (error, link still payable) |
| Slow processing | The completion page polls and turns into "Payment complete" after a few seconds |

Nothing reaches Stripe: payments live in the cache. The real Stripe.js is never loaded in the sandbox, and the sandbox bank's route only exists while the flag is on.

**Browser check of the whole flow** (Playwright, in `tools/viewport-check`): start the app with the sandbox on, then

```sh
URLS="$(php artisan axispay:checkout:demo --json)" node tools/viewport-check/checkout-flow.mjs --shots tools/viewport-check/shots
```

It pays with a decline, the security check and 3D Secure; checks "already paid" from another session, processing with polling, a failed verification with every payer field, the expired, canceled and unknown links. The responsive check works on the same URLs: `BASE_URL=http://pay.localhost:8000 PATHS=/l/<token>,... node check.mjs`.

### Turnstile keys

Cloudflare's documented test keys work on any host, including `*.localhost`:

| Key | Value | Behaviour |
|---|---|---|
| Site key | `1x00000000000000000000AA` | Visible widget, always passes |
| Site key | `2x00000000000000000000AB` | Visible widget, always fails |
| Site key | `3x00000000000000000000FF` | Forces an interactive challenge |
| Secret key | `1x0000000000000000000000000000000AA` | Server check always passes |
| Secret key | `2x0000000000000000000000000000000AA` | Server check always fails |

Production uses the keys of a Turnstile widget created in the Cloudflare dashboard for the pay host.

### Card-testing protection, locally

The per-link and per-IP counters live in the cache: `php artisan cache:clear` resets them. A link blocked after repeated declines is unblocked from its detail in the tenant panel (**Unblock payments**, permission `links:cancel`).

### With real Stripe test keys

Set the platform keys (`STRIPE_TEST_SECRET`, `STRIPE_TEST_PUBLISHABLE`), leave the sandbox off and forward the Connect events: `stripe listen --forward-connect-to localhost/webhooks/stripe/connect/test` (for an `api_key` connection, forward to its direct endpoint). The payment events the platform handles are listed by `php artisan axispay:doctor`.

### Phase 4 acceptance gate (contract tests)

Phase 4 is accepted only when the checkout contract tests pass with real test-mode keys (ADR-0051, "Stripe acceptance gate"):

```sh
STRIPE_TEST_SECRET=sk_test_… STRIPE_CONTRACT_CONNECTED_ACCOUNT=acct_… \
STRIPE_CONTRACT_RESTRICTED_KEY=rk_test_… STRIPE_CONTRACT_PUBLISHABLE_KEY=pk_test_… \
./vendor/bin/pest --group=stripe
```

The connected account must be an MX account that can charge. The items that need a browser (updating the card form, Mukta inside Stripe's iframe, the 3D Secure dialog) and the cost of voided authorizations are checked by hand and recorded in the ADR.

### After deploying Phase 4

1. Run the migrations.
2. `php artisan axispay:stripe-sync-webhook-endpoints` so every `api_key` endpoint receives the payment events.
3. Add the six `payment_intent.*` events to both Connect destinations in the Stripe Dashboard (`axispay:doctor` prints the list).
4. Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY`; make sure `AXISPAY_CHECKOUT_SANDBOX` is not set.

## Quality checks

| Command | What it does |
|---|---|
| `composer test` | Pest suite against `axispay_testing` on the Docker MariaDB, without the multi-process concurrency tests. |
| `composer test:concurrency` | Multi-process idempotency race tests; rebuild the `_testing` database. |
| `composer analyse` | Larastan, PHPStan level max. |
| `composer format` | Fixes code style with Pint. |
| `composer format:check` | Checks code style without changing files. |
| `composer ci` | `format:check`, `analyse`, `test` and `test:concurrency`, as CI runs them. |

The test suite needs the MariaDB container running. Its connection settings
live in `phpunit.xml` (`<env>` entries), so they do not depend on `.env`.

## Multi-tenancy rules in practice

- Every model on a table with `tenant_id` uses `BelongsToTenant`; list the
  table in `config/tenancy.php` (a test enforces both).
- Never `DB::table('<tenant table>')`, raw SQL on tenant tables or
  `withoutGlobalScope(s)` outside the whitelist in `config/tenancy.php`:
  PHPStan fails (`axispay.tenantTableAccess`, `axispay.rawTenantSql`,
  `axispay.scopeBypass`).
- Cross-tenant work: `TenantContext::runAsTenant()` per tenant, or
  `runAsPlatform($reason, ...)` (audited).
- Queued tenant jobs implement `TenantAware` and use `CapturesTenantContext`.
- New tenant-panel resources need an entry in the isolation dataset
  (`tests/Feature/Isolation/TenantIsolationTest.php`); the route coverage test
  fails otherwise.

## Database conventions

- Default charset and collation: `utf8mb4` / `utf8mb4_uca1400_ai_ci`.
- ULIDs, tokens, hashes, idempotency keys and provider IDs use `ascii_bin`
  through the schema macros: `$table->ulidAscii('id')->primary()`,
  `$table->foreignUlidAscii('tenant_id')`, `$table->asciiString('idempotency_key')`,
  `$table->asciiChar('key_hash', 64)`, `->asciiBin()`.
- Business datetimes use `DATETIME(6)` in UTC: `$table->dateTime('paid_at', 6)`
  and `$table->datetimes(6)`, never `timestamp()`.
- Money is `BIGINT` minor units plus `CHAR(3)` currency; exchange rates are
  `DECIMAL(18,6)`. Never floats.
- Run migrations in production with the migrator user:
  `php artisan migrate --force --database=mariadb_migrator`. The production
  image does this at deploy time (`RUN_MIGRATIONS=true` on the web service).

## Scheduling

Scheduled tasks live in `routes/console.php`:

| Task | When | What |
|---|---|---|
| `axispay:gateways:check-api-keys` | Daily 06:00 | Queues one health check per api_key connection (plan 12.3.3) |
| `axispay:provider-events:purge` | Daily 03:30 | Deletes ignored/unroutable webhook events older than 7 days; keeps only a reduced payload of processed ones after 30 days (plan 14.4) |
| `ExpirePaymentLinksJob` (queued) | Every minute | Expires active links whose expiry has passed (plan 9.1, ADR-0048) |
| `axispay:idempotency:purge` | Hourly | Deletes API idempotency records older than 24 hours (plan 7.8) |
| `axispay:payment-links:reconcile-gateways` | Every 15 minutes | Queues the cancellation of active links in tenant modes that no longer have a gateway connection (plan 12.3.4) |
| `axispay:payments:reconcile` | Every 15 minutes | Queues one reconciliation per tenant and mode: re-reads open payment attempts from Stripe, voids authorizations not captured after 15 minutes, releases links stuck in processing (plan 12.5, ADR-0051) |
| `axispay:cache:purge-expired` | Hourly (database cache store only) | Deletes expired cache rows, such as per-IP failed-authentication counters that are never read again |

Every task **must** use `withoutOverlapping()` and `onOneServer()`:

```php
Schedule::command('some:command')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
```

Both take their locks from the default cache store, which is `database`, shared
by every container. That keeps a task from running twice when two schedulers
overlap: a start-first deploy, an extra replica, or the all-in-one container
next to another deployment (ADR-0039).

## Continuous integration

`.github/workflows/ci.yml` runs Pint, Larastan and Pest on PHP 8.4 and 8.5
against a `mariadb:11.8.9` service configured like `docker/mariadb/conf.d`.
When MariaDB is upgraded, change `compose.yaml` and the workflow together.

## Production image

`docker build -t axispay:local .` builds the production image. To run its
roles against the local database, see "Run the production image locally" in
[deployment/dokploy.md](deployment/dokploy.md#run-the-production-image-locally).
