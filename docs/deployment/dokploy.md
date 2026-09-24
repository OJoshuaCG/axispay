# Deploying on Dokploy

AxisPay runs on [Dokploy](https://docs.dokploy.com) as **four Applications built from the same Dockerfile**: `web`, `worker-critical`, `worker-default` and `scheduler`. The four Applications live in each Dokploy environment (staging and production).

- `web` serves the four hosts behind Traefik with HTTPS and runs the migrations before it takes traffic.
- The workers and the scheduler wait until the schema is up to date before they start.

Why this shape: [ADR-0036](../adr/0036-dokploy-deployment.md). What the image does: [ADR-0035](../adr/0035-production-container-image.md).

> **Scope.** This guide covers what exists today: Phases 0–1 (tenancy, identity, panels). Stripe, Banxico and Turnstile settings arrive in later phases; they are listed under [Not needed yet](#not-needed-yet).
>
> **Verified against** the Dokploy docs and the Dokploy v0.30.6 source on 2026-09-24. Items marked **(verify)** could not be confirmed in the docs. Check them in your Dokploy version.

## Quick path

1. [Prepare](#1-prerequisites): push the repository to a Git provider, create DNS records, set up the external MariaDB with two users.
2. In Dokploy, create the project and the environments: **staging** and **production**.
3. In each environment, set the [shared variables](#3-shared-environment-variables) once.
4. [Create the `web` Application](#4-create-the-web-application): Git source, Dockerfile build, variables, four domains, Swarm health check. Deploy it.
5. [Create `worker-critical`, `worker-default` and `scheduler`](#5-create-the-worker-and-scheduler-applications): same source, different `CONTAINER_ROLE`, no domains. Deploy them.
6. [Verify](#6-verify): `https://app.<domain>/login` and `https://admin.<domain>/login` load, `/up` returns 200, and every Application shows a running task.
7. [Create the first platform admin](#7-first-run-create-the-first-platform-admin) from the `web` terminal.

---

## 1. Prerequisites

### 1.1 Dokploy server

- Dokploy is installed ([installation docs](https://docs.dokploy.com/docs/core/installation)), and you can sign in to its dashboard.
- Ports 80 and 443 are open to the internet. Traefik uses them, including for Let's Encrypt HTTP challenges.
- Server clock synchronization (NTP/chrony) is on (plan 25.1).

### 1.2 Repository

The repository has **no remote yet**. Push it first:

```sh
git remote add origin git@github.com:<org>/<repo>.git
git push -u origin main
```

In Dokploy, connect the provider under **Settings → Git** (GitHub, GitLab, Bitbucket or Gitea). A plain Git URL with an SSH key also works. For auto-deploy without extra setup, use GitHub ([auto-deploy docs](https://docs.dokploy.com/docs/core/auto-deploy)).

### 1.3 DNS

Create one `A` record per host, pointing to the Dokploy server's public IP. Use separate hosts per environment:

| Host | Surface | Available |
|---|---|---|
| `api.<domain>` | Public API v1 | Routes arrive in Phase 3 |
| `app.<domain>` | Tenant panel | Now |
| `admin.<domain>` | Platform (superadmin) panel | Now |
| `pay.<domain>` | Checkout | Phase 4 (the domain can be added now) |

For staging, use for example `api.staging.<domain>`. Let's Encrypt only issues the certificate once the record resolves.

### 1.4 External MariaDB

Production uses a dedicated MariaDB server outside Dokploy ([ADR-0026](../adr/0026-local-and-production-database.md)).

- **Version:** it must match the version pinned for local and CI (`mariadb:11.8.9`). **Open item:** confirm the exact version of the production server and align `compose.yaml` and CI with it.
- **Server settings:** `utf8mb4` / `utf8mb4_uca1400_ai_ci`, strict `sql_mode`, UTC (see `docker/mariadb/conf.d/axispay.cnf`). The application also enforces these per session.
- **Network:**
  - Allow port 3306 **only from the Dokploy server's IP** on the database server's firewall.
  - Use TLS if the traffic crosses an untrusted network. See [TLS to the database](#tls-to-the-database).
- **Backups:** backups, binlogs and restore tests are the database server's job (plan 25.2). Dokploy's database backups only cover databases that Dokploy itself manages, so they do not apply here.

Run this on the database server once per environment. Replace `<dokploy-ip>`, the database name and the passwords. Use a different database and different users for staging and production.

```sql
CREATE DATABASE axispay CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

-- Runtime user: DML only, no DDL (plan 25.5).
CREATE USER 'axispay_app'@'<dokploy-ip>' IDENTIFIED BY '<app-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON axispay.* TO 'axispay_app'@'<dokploy-ip>';

-- Migrations user: DDL on the application schema only; used at deploy time.
CREATE USER 'axispay_migrator'@'<dokploy-ip>' IDENTIFIED BY '<migrator-password>';
GRANT ALL PRIVILEGES ON axispay.* TO 'axispay_migrator'@'<dokploy-ip>';

-- With TLS required (recommended across untrusted networks):
-- ALTER USER 'axispay_app'@'<dokploy-ip>' REQUIRE SSL;
-- ALTER USER 'axispay_migrator'@'<dokploy-ip>' REQUIRE SSL;
```

The migrations create two triggers that make `audit_logs` append-only. If the server has binary logging enabled (needed for point-in-time recovery), MariaDB can refuse `CREATE TRIGGER` for a user without `SUPER` (error 1419). See [Troubleshooting](#troubleshooting).

The `axispay_backup` and `axispay_readonly` users (plan 25.5) belong to the database operator and are not used by the application.

---

## 2. Project and environments

1. **Projects → Create Project.** Name: `axispay`.
2. A new project starts with one environment. Create environments until you have **staging** and **production**.
   - Services in different environments are isolated, and each environment has its own variables ([docs](https://docs.dokploy.com/docs/core/multi-tenancy)).
   - The exact button label varies between versions **(verify)**.
3. Repeat steps 3–7 of this guide in each environment.

| Environment | Auto deploy | Branch | Data |
|---|---|---|---|
| staging | On | `main` | Test data only, never real data (plan 25.3) |
| production | **Off**; deploy with the Deploy button | `main` (or a release branch) | Real |

Dokploy has no approval step. Limit who can deploy to production with Dokploy's user permissions.

---

## 3. Shared environment variables

Open the environment and edit its **environment variables**. Define the values that all four Applications share, once per environment. Each Application then references them with `${{environment.NAME}}` ([variables docs](https://docs.dokploy.com/docs/core/variables)).

Secrets are marked **secret**: set them in Dokploy and never commit them.

| Variable | Required | Example (non-secret) | Description |
|---|---|---|---|
| `APP_NAME` | yes | `AxisPay` | Fixed internal name: it drives the cache, Redis and session prefixes. Keep `AxisPay`; do not change it to rebrand (ADR-0037). |
| `AXISPAY_DISPLAY_NAME` | no | `AxisPay` | Public name shown to people (pages, panels, 2FA issuer, mail sender). Change this one to rebrand. |
| `APP_ENV` | yes | `production` (also on staging) | Laravel environment. Never `local`: the dev seeder and the dev commands only run with `local`. |
| `APP_KEY` | yes | **secret** | `base64:…`. Generate once per environment (see below) and **back it up outside Dokploy**, separately from database backups (plan 23.2). Losing it makes the encrypted data (2FA secrets, PII) unreadable. |
| `APP_DEBUG` | yes | `false` | Never `true` outside local |
| `APP_URL` | yes | `https://app.example.com` | Base URL for generated links outside a request (e-mails, queued jobs) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | no | `en` / `en` | Default locale |
| `AXISPAY_API_HOST` | yes | `api.example.com` | Must match the Dokploy domain exactly |
| `AXISPAY_APP_HOST` | yes | `app.example.com` | " |
| `AXISPAY_ADMIN_HOST` | yes | `admin.example.com` | " |
| `AXISPAY_PAY_HOST` | yes | `pay.example.com` | " |
| `DB_CONNECTION` | yes | `mariadb` | Only MariaDB is supported |
| `DB_HOST` / `DB_PORT` | yes | `db.internal.example.com` / `3306` | External database server |
| `DB_DATABASE` | yes | `axispay` | Different per environment |
| `DB_USERNAME` | yes | `axispay_app` | Runtime user (DML only) |
| `DB_PASSWORD` | yes | **secret** | |
| `SESSION_DRIVER` | yes | `database` | |
| `SESSION_SECURE_COOKIE` | yes | `true` | Cookies only over HTTPS (plan 23.4) |
| `SESSION_LIFETIME` | no | `120` | Minutes |
| `CACHE_STORE` | yes | `database` | |
| `QUEUE_CONNECTION` | yes | `database` | ADR-0016 |
| `TRUSTED_PROXIES` | yes | `10.0.0.0/8` | Traefik's network. See [Trusted proxies](#trusted-proxies). |
| `MAIL_MAILER` | yes | `smtp` | Invitations and owner notifications are sent today |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_SCHEME` | yes | `smtp.postmarkapp.com` / `587` / `smtp` | Transactional SMTP (plan 5) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | yes | **secret** | |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | yes | `no-reply@example.com` / `AxisPay` | `MAIL_FROM_NAME` defaults to `AXISPAY_DISPLAY_NAME` |
| `LOG_LEVEL` | no | `info` | The image already logs JSON to stderr |
| `SENTRY_LARAVEL_DSN` | no | **secret** | Error tracking; disabled while empty (ADR-0029) |
| `SENTRY_ENVIRONMENT` | no | `production` / `staging` | |
| `AXISPAY_PASSWORD_CHECK_UNCOMPROMISED` | no | `true` | Breached-password check (needs outbound HTTPS to the HIBP API) |

**Do not set `SESSION_DOMAIN`.** Leave it out, or leave it empty. The application refuses to boot when it is set (ADR-0034), because each panel host keeps its own host-only cookie.

To generate `APP_KEY` without a local PHP, use any machine with Docker:

```sh
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Values the image already sets (`APP_ENV=production`, `LOG_CHANNEL=stderr`, the JSON formatter, `LOG_LEVEL=info`) can be overridden, but they do not need to be set.

---

## 4. Create the `web` Application

In the environment: **Create Service → Application**. Name: `web`.

### 4.1 General tab: source and build

| Field | Value |
|---|---|
| Provider | Your Git provider (for example GitHub), repository, branch `main` |
| Build Type | **Dockerfile** |
| Dockerfile Path | `Dockerfile` |
| Docker Context Path | `.` |
| Docker Build Stage | `runtime` (optional; it is the last stage) |
| Auto Deploy | On for staging, off for production. The toggle is in the General tab ([docs](https://docs.dokploy.com/docs/core/auto-deploy)). |

No build arguments or build secrets are needed: the image holds no configuration.

### 4.2 Environment tab

Reference the shared variables, then add the ones that only `web` needs:

```dotenv
CONTAINER_ROLE=web
RUN_MIGRATIONS=true
DB_MIGRATOR_USERNAME=axispay_migrator
DB_MIGRATOR_PASSWORD=<secret, set in Dokploy>

APP_NAME=${{environment.APP_NAME}}
AXISPAY_DISPLAY_NAME=${{environment.AXISPAY_DISPLAY_NAME}}
APP_ENV=${{environment.APP_ENV}}
APP_KEY=${{environment.APP_KEY}}
APP_DEBUG=${{environment.APP_DEBUG}}
APP_URL=${{environment.APP_URL}}
AXISPAY_API_HOST=${{environment.AXISPAY_API_HOST}}
AXISPAY_APP_HOST=${{environment.AXISPAY_APP_HOST}}
AXISPAY_ADMIN_HOST=${{environment.AXISPAY_ADMIN_HOST}}
AXISPAY_PAY_HOST=${{environment.AXISPAY_PAY_HOST}}
DB_CONNECTION=${{environment.DB_CONNECTION}}
DB_HOST=${{environment.DB_HOST}}
DB_PORT=${{environment.DB_PORT}}
DB_DATABASE=${{environment.DB_DATABASE}}
DB_USERNAME=${{environment.DB_USERNAME}}
DB_PASSWORD=${{environment.DB_PASSWORD}}
SESSION_DRIVER=${{environment.SESSION_DRIVER}}
SESSION_SECURE_COOKIE=${{environment.SESSION_SECURE_COOKIE}}
CACHE_STORE=${{environment.CACHE_STORE}}
QUEUE_CONNECTION=${{environment.QUEUE_CONNECTION}}
TRUSTED_PROXIES=${{environment.TRUSTED_PROXIES}}
MAIL_MAILER=${{environment.MAIL_MAILER}}
MAIL_HOST=${{environment.MAIL_HOST}}
MAIL_PORT=${{environment.MAIL_PORT}}
MAIL_SCHEME=${{environment.MAIL_SCHEME}}
MAIL_USERNAME=${{environment.MAIL_USERNAME}}
MAIL_PASSWORD=${{environment.MAIL_PASSWORD}}
MAIL_FROM_ADDRESS=${{environment.MAIL_FROM_ADDRESS}}
MAIL_FROM_NAME=${{environment.MAIL_FROM_NAME}}
SENTRY_LARAVEL_DSN=${{environment.SENTRY_LARAVEL_DSN}}
SENTRY_ENVIRONMENT=${{environment.SENTRY_ENVIRONMENT}}
```

`web`-only settings:

| Variable | Default | Description |
|---|---|---|
| `CONTAINER_ROLE` | `web` | Role of the container |
| `RUN_MIGRATIONS` | `false` | `true` on `web` only: before serving, it runs `migrate --force --database=mariadb_migrator`, then the idempotent permission-catalog seed |
| `DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD` | none | **secret**. DDL user. Set it on `web` only; it is removed from the environment before PHP-FPM starts |
| `RELEASE_SEED` | `true` | `false` skips `db:seed` after migrating |
| `PHP_FPM_MAX_CHILDREN` | `10` | FPM workers per container; size against the memory limit (about 50–80 MB each) |
| `DB_WAIT_SECONDS` | `60` | How long to wait for the database at start |

### 4.3 Domains tab

Add four domains, one per host. All four point to the same container port:

| Host | Path | Container Port | HTTPS | Certificate |
|---|---|---|---|---|
| `api.<domain>` | `/` | `8080` | On | Let's Encrypt |
| `app.<domain>` | `/` | `8080` | On | Let's Encrypt |
| `admin.<domain>` | `/` | `8080` | On | Let's Encrypt |
| `pay.<domain>` | `/` | `8080` | On | Let's Encrypt |

- Leave **Internal Path** and **Strip Path** empty.
- For Applications, domain changes apply without a redeploy ([domains docs](https://docs.dokploy.com/docs/core/domains)).
- Do not use the generated `traefik.me` domains. They are HTTP-only, and the hosts must match the `AXISPAY_*_HOST` variables.
- Do not add anything under **Advanced → Ports**. Traefik reaches the container over Dokploy's network, and the container port must not be published to the internet.

### 4.4 Advanced tab

**Swarm Settings → Health Check.** The image already has a role-aware `HEALTHCHECK`. Set it here explicitly so that the start period covers the migrations. Durations are in nanoseconds ([zero-downtime docs](https://docs.dokploy.com/docs/core/applications/zero-downtime)).

```json
{
  "Test": ["CMD", "axispay-healthcheck"],
  "Interval": 10000000000,
  "Timeout": 5000000000,
  "StartPeriod": 180000000000,
  "Retries": 3
}
```

**Swarm Settings → Update Config.** Start the new task first. Switch only when it is healthy, and roll back on failure:

```json
{
  "Parallelism": 1,
  "Delay": 10000000000,
  "FailureAction": "rollback",
  "Monitor": 30000000000,
  "Order": "start-first"
}
```

**Swarm Settings → Restart Policy.** `{"Condition": "any", "Delay": 5000000000}`. The workers exit on purpose after `queue:restart`, after `--memory`, and on SIGTERM.

**Resources** (optional). The values are in bytes and nanoCPUs, and units like `1g` are rejected:

| Setting | Suggested |
|---|---|
| Memory limit | `1073741824` (1 GiB) |
| Memory reservation | `268435456` (256 MiB) |
| CPU limit | `1000000000` (1 core) |

**Cluster Settings → Replicas.** Keep `1` for the first deploy. You can raise it afterwards; see [Scaling](#scaling).

Leave **Run Command** empty: the role comes from `CONTAINER_ROLE`.

### 4.5 Deploy

Click **Deploy**. The deployment log shows the Docker build, then the service update. In **Logs** you should see JSON lines like:

```text
{"message":"Running migrations with the migrator connection",...}
{"message":"Release finished",...}
{"message":"Web role started (nginx :8080, php-fpm max_children=10)",...}
```

---

## 5. Create the worker and scheduler Applications

Create three more Applications in the same environment. Each has the same **General** settings as `web`: same repository, branch, Dockerfile, context and Auto Deploy setting. None has a domain.

| Application | Environment tab (in addition to the shared references, **without** the `DB_MIGRATOR_*` variables) | Update Config `Order` |
|---|---|---|
| `worker-critical` | `CONTAINER_ROLE=worker`, `QUEUE_NAMES=critical` | `start-first` |
| `worker-default` | `CONTAINER_ROLE=worker`, `QUEUE_NAMES=default,low` | `start-first` |
| `scheduler` | `CONTAINER_ROLE=scheduler` | **`stop-first`**. Only one scheduler may run at a time. |

Paste the same `${{environment.…}}` block as in 4.2, without `RUN_MIGRATIONS` and the `DB_MIGRATOR_*` lines.

Worker settings:

| Variable | Default | Description |
|---|---|---|
| `QUEUE_NAMES` | `critical,default,low` | Queues in priority order |
| `QUEUE_TIMEOUT` | `60` | Seconds per job before it is killed. Keep it below the database queue's `retry_after` (90). |
| `QUEUE_TRIES` | `3` | Attempts per job |
| `QUEUE_SLEEP` | `3` | Seconds to sleep when the queue is empty |
| `QUEUE_MEMORY` | `192` | MB. Above this the worker exits, and Swarm starts a fresh one. |
| `WAIT_FOR_MIGRATIONS` | `true` | Wait for pending migrations before starting |
| `MIGRATIONS_WAIT_SECONDS` | `300` | Give up after this long; the task restarts |

**Advanced tab for each of the three:**

- **Health Check:** leave it empty. The image `HEALTHCHECK` checks that the `queue:work` or `schedule:work` process is alive. When the field is empty, Dokploy sends no health check and Swarm uses the image's.
- **Update Config** (worker example):

  ```json
  {"Parallelism": 1, "FailureAction": "rollback", "Order": "start-first"}
  ```

  For `scheduler`, use `"Order": "stop-first"`.

- **Restart Policy:** `{"Condition": "any", "Delay": 5000000000}`.
- **Stop grace period:** 90 seconds for the workers, which is more than `QUEUE_TIMEOUT`, so a running job can finish on SIGTERM. The Dokploy v0.30 source has a `stopGracePeriodSwarm` setting (nanoseconds: `90000000000`), but the docs do not show where it appears in the UI **(verify)**. Without it Docker's default is 10 seconds. A job cut off by the default grace period is retried after `retry_after`, so jobs must stay idempotent.
- **Resources** (suggested): memory limit `536870912` (512 MiB) for the workers and `268435456` (256 MiB) for the scheduler.
- **Replicas:** `1` for each. For the scheduler, always exactly `1`.

Deploy all three. Their logs show `Worker started on queues critical`, `Worker started on queues default,low` and `Scheduler started (schedule:run every minute)`. If a deploy of `web` is still migrating, they wait for it first.

---

## 6. Verify

| Check | Expected |
|---|---|
| `curl -sI https://app.<domain>/login` | `200`, `Strict-Transport-Security` header, `Set-Cookie: axispay_app_session=…; secure; httponly` |
| `curl -sI https://admin.<domain>/login` | `200`, cookie `axispay_admin_session` (a different cookie from the app host) |
| `curl -s -o /dev/null -w '%{http_code}' https://app.<domain>/up` | `200` |
| Page source of `/login` | Asset URLs start with `https://` (no mixed content) |
| Each Application → **Logs** | JSON lines, no errors |
| Each Application → **Monitoring** | One running container |

Monitoring graphs only update while the page is open.

## 7. First run: create the first platform admin

Open a terminal in the `web` container: the Application's terminal option, or `docker exec -it <container> bash` on the server. Dokploy has a container terminal, but the docs do not say which screen it is on **(verify)**. Then run:

```sh
php artisan axispay:create-platform-admin
```

The command is interactive and never takes the password as an argument. Sign in at `https://admin.<domain>` and set up 2FA (mandatory for platform admins).

Never run `db:seed --class=DevelopmentSeeder` outside local: it refuses unless `APP_ENV=local`.

---

## Operating the deployment

### Deploys and ordering

- Pushing to the branch (staging), or clicking **Deploy** (production), builds each Application's image and rolls its service.
- New `web` tasks migrate first, while the old `web` task keeps serving.
- The workers and the scheduler of the new version wait until no migration is pending.

Because the old code keeps running during a deploy, **migrations must follow expand/contract** (plan 25.4): never drop or rename a column in the same deploy that stops using it.

`queue:restart` is not part of a deploy. Each worker service is replaced, and a worker finishes its current job on SIGTERM.

### One-off Artisan commands

Use the `web` container's terminal: `php artisan <command>`. Examples: `php artisan about`, `php artisan migrate:status`, `php artisan queue:failed`.

- The runtime user has no DDL rights, so run migrations only through a deploy.
- To retry failed jobs: `php artisan queue:retry all`.

### Logs

- Every role writes JSON lines to stderr, redacted by `RedactSensitiveLogData`. Read them in each Application's **Logs** tab.
- Nothing is written to files inside the container.
- Nginx and PHP-FPM access logs are off, because URLs can carry single-use tokens. Enable Traefik access logs on the Dokploy server if you need request logs **(verify the Traefik settings screen in your version)**.
- Shipping logs to an aggregator (Loki, Graylog; plan 24.1) is configured on the server, outside this repository.

### Rollback

| Situation | What to do |
|---|---|
| New `web` task never becomes healthy | Nothing: Swarm rolls back automatically (`FailureAction: rollback`), and the old task keeps serving. |
| New release is healthy but broken | Revert the commit, push, and deploy. Or use Dokploy's **Rollback** button, which needs **Deployments → Rollback Settings** with a registry ([rollbacks docs](https://docs.dokploy.com/docs/core/applications/rollbacks)). |
| A migration must be undone | Never automatic. Write a new forward migration. Expand/contract keeps the previous code compatible. |

### Redeploy on push

- **GitHub:** the Auto Deploy toggle is enough.
- **GitLab, Bitbucket, Gitea:** turn on Auto Deploy, copy the Application's webhook URL (shown under **Deployments**), and add it as a push webhook in the repository settings. Do this for each of the four Applications. The branch must match, or Dokploy answers "Branch Not Match" ([docs](https://docs.dokploy.com/docs/core/auto-deploy)).

### Scaling

- `web` can run 2 or more replicas once it has been deployed once. Updates roll one task at a time, and each new task runs `migrate`, which returns "Nothing to migrate" after the first one.
- On the very first deploy, keep 1 replica. Parallel first starts would migrate concurrently.
- Add worker replicas to drain queues faster.
- Keep the scheduler at 1.

### Build once (later)

Each Application builds the same image. On a single server the Docker layer cache makes the repeated builds cheap **(verify that your builds show cached layers)**.

When a registry is available:

1. Build and push the image in CI.
2. Switch the four Applications to the Docker provider with that image.
3. Enable registry-based rollbacks.

The Dokploy docs recommend this for production ([going to production](https://docs.dokploy.com/docs/core/applications/going-production)).

### Backups

| What | Where |
|---|---|
| Database (full daily backup, binlogs for point-in-time recovery, monthly restore test; plan 25.2) | On the external MariaDB server. Dokploy's backups do not cover it. |
| `APP_KEY` | A password manager or vault, **separate** from database backups |
| `GATEWAY_CREDENTIALS_KEY` (Phase 2+) | Separate from both `APP_KEY` and the database backups (plan 23.2) |
| Dokploy itself | **Web Server → Backups** covers Dokploy's own data, not the application |

---

## Security checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` not set.
- [ ] Every secret is set in Dokploy only; `.env` is never committed and the image contains none.
- [ ] `DB_MIGRATOR_*` is set on `web` only.
- [ ] The database firewall allows 3306 only from the Dokploy server; TLS is used across untrusted networks.
- [ ] `TRUSTED_PROXIES` is Traefik's network range, not `*`; no container port is published under **Advanced → Ports**.
- [ ] HTTPS with Let's Encrypt on all four hosts; HSTS is sent by the app. Preload is not enabled; that decision belongs to the owner of the domain.
- [ ] **Admin host allowlist** (plan 4.1, recommended); see below.
- [ ] Platform admins have 2FA (enforced) and are created only with `axispay:create-platform-admin`.
- [ ] `APP_KEY` is backed up outside Dokploy.

### Admin host IP allowlist

Dokploy writes each Application's Traefik routers to a dynamic file that you can edit in the Application's **Advanced → Traefik** section ([domains docs](https://docs.dokploy.com/docs/core/domains)). An IP allowlist is a standard Traefik `ipAllowList` middleware. The Dokploy docs show custom middlewares in that file, but not `ipAllowList` specifically **(verify)**.

1. Add the middleware definition:

   ```yaml
   http:
     middlewares:
       admin-allowlist:
         ipAllowList:
           sourceRange:
             - "203.0.113.10/32"   # office or VPN egress IP
   ```

2. Add `admin-allowlist` to the `middlewares` list of the router whose rule is ``Host(`admin.<domain>`)``, and only that router.
3. Test it from an allowed IP and from a different IP (expect `403`).

Dokploy regenerates a domain's routers when you edit that domain, which drops the middleware from the router. **Re-attach it after every change to the admin domain.** Basic auth (**Advanced → Security**) would apply to all four hosts of `web`, so do not use it for this.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `419 Page Expired` on sign-in, or sign-in loops | The browser drops the `secure` cookie because Laravel thinks the request is HTTP, or `APP_URL` or the hosts do not match | Set `TRUSTED_PROXIES` to Traefik's network and `SESSION_SECURE_COOKIE=true`; check that `AXISPAY_*_HOST` match the domains exactly |
| Assets load over `http://` (mixed content), redirects go to `http://` | The proxy is not trusted, so `X-Forwarded-Proto` is ignored | Set `TRUSTED_PROXIES` (see below) and redeploy |
| Container exits at boot: `SESSION_DOMAIN must be empty (null)` | `SESSION_DOMAIN` is set (ADR-0034) | Remove the variable |
| `Missing required environment variable APP_KEY` | A variable is not set, or the `${{environment.…}}` reference is wrong | Check the Environment tab and the shared variables |
| Migrations fail: `CREATE command denied` / `ALTER command denied` | Migrations are running as `axispay_app` | Set `DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD` on `web` and check the migrator grants |
| Migrations fail with error 1419 on `CREATE TRIGGER` (`You do not have the SUPER privilege and binary logging is enabled`) | Binary logging is on and the migrator is not trusted to create triggers | On the database server, set `log_bin_trust_function_creators = 1`, or have the DBA grant the required privilege (verify with the DBA for your MariaDB version) |
| Workers or scheduler log `Migrations are still pending…` and restart | `web` has not finished migrating, or it runs without `RUN_MIGRATIONS=true` | Check the `web` deploy log; deploy `web` |
| Jobs pile up in `jobs` and nothing runs | Worker services are stopped, or listening on the wrong queue | Check `QUEUE_NAMES` and the worker logs; `php artisan queue:monitor database:critical,database:default,database:low` |
| Scheduled tasks do not run | The `scheduler` Application is missing or stopped | Deploy it; exactly one replica |
| Scheduled tasks run twice around a deploy | Scheduler Update Config uses `start-first` | Use `"Order": "stop-first"` |
| `web` never becomes healthy, deploy rolls back | The start period is too short for the migrations, or the database is unreachable | Increase `StartPeriod`; check the logs (`Database connection … is not reachable`) and the database firewall |
| Health check fails, but the site works through the domain | A custom health check calls the public host, or uses the wrong port | Use `["CMD", "axispay-healthcheck"]`. `/up` is not tied to a host and answers on `127.0.0.1:8080`. |
| `404` for every page on a host | The host is not equal to the `AXISPAY_*_HOST` value (routes are bound with `Route::domain()`) | Make the domain and the variable identical |
| Let's Encrypt certificate is not issued | DNS is not pointing to the server yet, or port 80 is blocked | Fix DNS or the firewall; Traefik retries |

### Trusted proxies

Traefik reaches the containers over Dokploy's overlay network, `dokploy-network`. Find its range on the Dokploy server:

```sh
docker network inspect dokploy-network --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}'
```

Set `TRUSTED_PROXIES` to that range, for example `10.0.1.0/24`. Swarm overlay networks usually come from `10.0.0.0/8`. Never use `*` unless the container port is unreachable from anything except Traefik.

### TLS to the database

Mount the CA certificate as a file (**Advanced → Volumes → File Mount**, for example at `/etc/ssl/certs/axispay-db-ca.pem`), then set:

```dotenv
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/axispay-db-ca.pem
MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true
# Client certificates only if the server requires them:
# MYSQL_ATTR_SSL_CERT=...
# MYSQL_ATTR_SSL_KEY=...
```

Set them on all four Applications, for example as shared variables.

---

## Not needed yet

These settings are defined by later phases of the master plan (section 27). Do not add them before their phase documents them:

| Setting | Phase |
|---|---|
| Stripe platform keys, Connect webhook signing secrets (`api.<domain>/webhooks/stripe/connect/{mode}`) | Phase 2 |
| `GATEWAY_CREDENTIALS_KEY` (separate from `APP_KEY`; its own backup) | Phase 2 (used in 4B) |
| Cloudflare Turnstile keys; strict CSP on `pay.` | Phase 4 |
| Banxico SIE token | Phase 6 |
| `/health/live` and `/health/ready` for external uptime monitoring (plan 24.4) | Later; `/up` covers liveness today |

## Reference

| File | Purpose |
|---|---|
| `Dockerfile` | Multi-stage build (PHP extensions, Composer `--no-dev`, pnpm + Vite, runtime) |
| `docker/app/entrypoint.sh` | Roles `web`, `worker`, `scheduler`, `release`, `artisan` |
| `docker/app/healthcheck.sh` | Role-aware health check |
| `docker/app/nginx.conf`, `php-fpm.conf`, `php.ini` | Web server, FPM pool, OPcache and PHP settings |
| `config/trustedproxy.php` | `TRUSTED_PROXIES` |
| `.dockerignore` | Keeps `.env*`, `storage/`, `vendor/`, `node_modules/` out of the build |

### Run the production image locally

This runs against the Docker MariaDB from `compose.yaml` (network `axispay_default`, dev database). Use an env file with local, non-secret values only (see `.env.example`) and `DB_HOST=mariadb`, `DB_PORT=3306`:

```sh
docker build -t axispay:local .
docker run --rm --network axispay_default --env-file /tmp/axispay-local.env axispay:local release
docker run -d --name axispay-web --network axispay_default --env-file /tmp/axispay-local.env -p 18080:8080 axispay:local
docker run -d --name axispay-worker --network axispay_default --env-file /tmp/axispay-local.env -e CONTAINER_ROLE=worker axispay:local
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: app.localhost' http://127.0.0.1:18080/login
```
