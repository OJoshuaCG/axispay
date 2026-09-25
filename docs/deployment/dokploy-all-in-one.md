# Deploying the all-in-one container on Dokploy (staging, local and test servers)

This guide runs AxisPay as **one Dokploy Application**: a single container with the `all-in-one` role, where supervisord runs the web server, both queue workers and the scheduler. It is built from the same Dockerfile as production.

Use it for staging, demos and local or test Dokploy servers. Production uses four Applications: see [`dokploy.md`](dokploy.md). Why both shapes exist: [ADR-0039](../adr/0039-all-in-one-container-role.md).

> **Verified against** the Dokploy docs (docs.dokploy.com) on 2026-09-24. Items marked **(verify in your Dokploy version)** could not be confirmed there.

## When to use it

| Use all-in-one for | Do **not** use it for |
|---|---|
| Staging | **Production** |
| Demos and review environments | Anything with real payment data |
| A Dokploy server on your LAN or in a VM, for testing | Load or performance tests meant to reflect production |

Production keeps one Application per role ([ADR-0036](../adr/0036-dokploy-deployment.md)) because it gives:

- **Independent restarts and health.** A crashed worker never restarts the web server, and each role has its own health check.
- **No duplicate scheduler.** Only the scheduler Application uses stop-first updates; the web keeps zero-downtime start-first updates.
- **Isolated resources.** A heavy job cannot starve web requests of CPU or memory.
- **Separate logs** per role.

The all-in-one container gives all of that up in exchange for one Application to create and one deploy per push.

## Quick path

1. [Prepare](#1-prerequisites): Dokploy, the repository in a Git provider, a MariaDB 11.8 database with an app user and a migrator user.
2. [Create one Application](#2-create-the-application) with the Dockerfile build and `CONTAINER_ROLE=all-in-one`.
3. [Set the variables](#3-environment-variables) (one table).
4. [Add the four domains](#4-domains) → port `8080`.
5. [Advanced tab](#5-advanced-tab-health-update-order-stop-grace-period): health check, **update order `stop-first`**, stop grace period 150 s.
6. [Deploy and verify](#6-deploy-and-verify).
7. [Create the first platform admin](#7-create-the-first-platform-admin).

No TLS on a local test server? Read [Local test deployment without TLS](#local-test-deployment-without-tls) before step 3.

---

## What runs inside the container

The entrypoint runs the migrations (when `RUN_MIGRATIONS=true`), removes the migrator credentials from the environment, warms the caches, then starts supervisord. `tini` is PID 1 and forwards signals. Every process runs as `www-data`.

```text
tini (PID 1)
└── supervisord
    ├── php-fpm            127.0.0.1:9000
    ├── nginx              :8080  (the only port Traefik talks to)
    └── group "background"
        ├── worker-critical   queue:work --queue=critical
        ├── worker-default    queue:work --queue=default,low
        └── scheduler         schedule:work
```

| Program | Command | Stop signal | Waits up to | Start / stop order |
|---|---|---|---|---|
| `php-fpm` | `php-fpm --nodaemonize` | `QUIT` (finishes running requests) | 30 s | starts 1st, stops last |
| `nginx` | `nginx -g "daemon off;"` | `QUIT` (drains connections) | 30 s | starts 2nd, stops 2nd to last |
| `worker-critical` | `queue:work --queue=critical` | `TERM` (finishes the running job) | `QUEUE_TIMEOUT` + 15 s | starts last, stops first |
| `worker-default` | `queue:work --queue=default,low` | `TERM` | `QUEUE_TIMEOUT` + 15 s | starts last, stops first |
| `scheduler` | `schedule:work` | `TERM` (to its whole process group) | `QUEUE_TIMEOUT` + 15 s | starts last, stops first |

- The workers use the same flags as the production `worker` role (`QUEUE_SLEEP`, `QUEUE_TRIES`, `QUEUE_TIMEOUT`, `QUEUE_MEMORY`).
- The three background programs stop in parallel. Web requests keep being served until they are done.
- supervisord restarts any program that exits. A program that fails to start 3–5 times in a row is given up on; the health check then fails and Swarm replaces the container.
- There is no supervisord control socket, so `supervisorctl` does not work. Restart the container instead.

Configuration: `docker/app/supervisord.conf`. Role logic: `docker/app/entrypoint.sh`.

---

## 1. Prerequisites

### 1.1 Dokploy and the repository

- Dokploy is installed and you can sign in ([installation docs](https://docs.dokploy.com/docs/core/installation)).
- The repository is in a Git provider connected under **Settings → Git**. Details: [production guide, 1.2](dokploy.md#12-repository).

### 1.2 MariaDB 11.8

The version must be **11.8.x**, the same line as local and CI (`mariadb:11.8.9`). Choose one:

| Option | For | Notes |
|---|---|---|
| **External MariaDB server** | Staging | Same setup as production: [production guide, 1.4](dokploy.md#14-external-mariadb), with a separate database and separate users |
| **MariaDB service created in Dokploy** | Local or test servers | Quick; data lives in a Docker volume on the Dokploy server |

**MariaDB service in Dokploy** ([databases docs](https://docs.dokploy.com/docs/core/databases)):

1. In the same project and environment: **Create Service → Database → MariaDB** (the exact labels vary between versions **(verify in your Dokploy version)**).
2. Set the database name (`axispay`), user, password and root password.
3. Set the **Docker image** to `mariadb:11.8.9`. The docs describe a custom Docker image setting for database services; check that it is not left on a different default version **(verify in your Dokploy version)**.
4. Deploy it. Its connection section shows the **Internal Host**, **Internal Port (Container)**, user, password and database name ([connection docs](https://docs.dokploy.com/docs/core/databases/connection)). Use the internal host as `DB_HOST`. Do not expose the database publicly.

### 1.3 Database users

The application uses two users: a runtime user with DML only and a migrator with DDL. Run this as root on the database (for a Dokploy MariaDB service, from its container terminal: `mariadb -uroot -p`). Use `'%'` as the host for a Dokploy service on the internal network, or the Dokploy server's IP for an external server.

```sql
CREATE DATABASE IF NOT EXISTS axispay CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

-- Runtime user: DML only, no DDL (plan 25.5).
CREATE USER 'axispay_app'@'%' IDENTIFIED BY '<app-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON axispay.* TO 'axispay_app'@'%';

-- Migrations user: DDL on the application schema; used at deploy time.
CREATE USER 'axispay_migrator'@'%' IDENTIFIED BY '<migrator-password>';
GRANT ALL PRIVILEGES ON axispay.* TO 'axispay_migrator'@'%';
```

On a throwaway local test server you may skip this and use the user that Dokploy created for both `DB_USERNAME` and `DB_MIGRATOR_USERNAME`. Which privileges Dokploy gives that user is not documented **(verify in your Dokploy version)**; migrations fail with `CREATE command denied` if it has too few. Staging always uses the two separate users.

Migrations create triggers on `audit_logs`. If binary logging is on, see error 1419 in the [production troubleshooting](dokploy.md#troubleshooting).

---

## 2. Create the Application

In the project's environment: **Create Service → Application**. Name: `axispay` (or `staging`).

**General tab:**

| Field | Value |
|---|---|
| Provider | Your Git provider, repository, branch `main` |
| Build Type | **Dockerfile** |
| Dockerfile Path | `Dockerfile` |
| Docker Context Path | `.` |
| Docker Build Stage | `runtime` (optional; it is the last stage) |
| Auto Deploy | On for staging if you want every push deployed |

Leave **Run Command** empty: the role comes from `CONTAINER_ROLE`.

---

## 3. Environment variables

Set them in the Application's **Environment** tab. With only one Application there is no need for environment-level shared variables, but you can use them (`${{environment.NAME}}`, [variables docs](https://docs.dokploy.com/docs/core/variables)).

| Variable | Example | Notes |
|---|---|---|
| `CONTAINER_ROLE` | `all-in-one` | **Required** for this guide |
| `RUN_MIGRATIONS` | `true` | Migrate with the migrator user before starting, then seed the permission catalog |
| `DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD` | `axispay_migrator` / **secret** | Removed from the environment before supervisord starts |
| `APP_NAME` | `AxisPay` | Fixed internal name; do not change it to rebrand (ADR-0037) |
| `AXISPAY_DISPLAY_NAME` | `AxisPay Staging` | Public name shown to people; a staging label here helps avoid confusion |
| `APP_ENV` | `production` | Also on staging. Never `local` |
| `APP_KEY` | **secret** | `base64:…`, one per environment. Generate: `docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'` |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://app.staging.example.com` | `http://app.axispay.test` [without TLS](#local-test-deployment-without-tls) |
| `AXISPAY_API_HOST` / `AXISPAY_APP_HOST` / `AXISPAY_ADMIN_HOST` / `AXISPAY_PAY_HOST` | `api.staging.example.com` … | Must equal the Dokploy domains exactly |
| `DB_CONNECTION` | `mariadb` | |
| `DB_HOST` / `DB_PORT` | internal host / `3306` | |
| `DB_DATABASE` | `axispay` | |
| `DB_USERNAME` / `DB_PASSWORD` | `axispay_app` / **secret** | Runtime user |
| `SESSION_DRIVER` | `database` | |
| `SESSION_SECURE_COOKIE` | `true` | **`false` only without TLS**, otherwise sign-in fails with `419` |
| `CACHE_STORE` | `database` | Also the lock store for scheduled tasks |
| `QUEUE_CONNECTION` | `database` | |
| `TRUSTED_PROXIES` | `10.0.1.0/24` | Traefik's network range. **Never `*`.** How to find it: [Trusted proxies](dokploy.md#trusted-proxies) |
| `MAIL_MAILER` + `MAIL_*` | `smtp` … | Staging: a sandbox SMTP (for example Mailpit or a provider's test inbox). A local test server can use `log` |
| `QUEUE_TIMEOUT` | `60` | Seconds per job. Keep it below the queue's `retry_after` (90) |
| `QUEUE_TRIES` / `QUEUE_SLEEP` / `QUEUE_MEMORY` | `3` / `3` / `192` | Same meaning as in production |
| `PHP_FPM_MAX_CHILDREN` | `10` | Minimum `6` (the pool's spare-server settings). Lower it on a small server |

- **Do not set `SESSION_DOMAIN`.** The application refuses to boot when it is set (ADR-0034).
- `QUEUE_NAMES` is ignored in this role: the queues are fixed to `critical` and `default,low`.
- Stripe, Banxico and Turnstile settings arrive in later phases: [Not needed yet](dokploy.md#not-needed-yet).

---

## 4. Domains

Add four domains on the Application, all to container port **8080** ([domains docs](https://docs.dokploy.com/docs/core/domains)):

| Host | Path | Container Port | HTTPS | Certificate |
|---|---|---|---|---|
| `api.<domain>` | `/` | `8080` | On | Let's Encrypt |
| `app.<domain>` | `/` | `8080` | On | Let's Encrypt |
| `admin.<domain>` | `/` | `8080` | On | Let's Encrypt |
| `pay.<domain>` | `/` | `8080` | On | Let's Encrypt |

- Without TLS, turn **HTTPS** off and use certificate **None**: see [below](#local-test-deployment-without-tls).
- Do not use the generated `traefik.me` domains: the hosts must match the `AXISPAY_*_HOST` variables.
- Do not publish port 8080 under **Advanced → Ports**.

---

## 5. Advanced tab: health, update order, stop grace period

Durations are in **nanoseconds** ([zero-downtime docs](https://docs.dokploy.com/docs/core/applications/zero-downtime)).

**Swarm Settings → Health Check.** The start period covers the migrations and cache warm-up:

```json
{
  "Test": ["CMD", "axispay-healthcheck"],
  "Interval": 10000000000,
  "Timeout": 5000000000,
  "StartPeriod": 180000000000,
  "Retries": 3
}
```

In this role the container is healthy only when `/up` answers on `127.0.0.1:8080` **and** both workers and the scheduler are running.

**Swarm Settings → Update Config.** Use **`stop-first`**:

```json
{
  "Parallelism": 1,
  "FailureAction": "rollback",
  "Order": "stop-first"
}
```

With `start-first`, the old and the new container would both run a scheduler during the switch, and every scheduled task would run twice. Stop-first means a short downtime on each deploy (the new container migrates and warms caches first, usually under a minute), which is acceptable on staging and test servers. If you keep `start-first` anyway, every scheduled task must use `onOneServer()` and `withoutOverlapping()` with the shared `database` cache store; that is already the rule for all scheduled tasks ([development guide](../development.md#scheduling)).

**Swarm Settings → Restart Policy.** `{"Condition": "any", "Delay": 5000000000}`.

**Stop grace period: 150 s** (`150000000000`). On stop, the workers get up to `QUEUE_TIMEOUT` + 15 s (75 s by default) to finish their jobs, then nginx and php-fpm up to 30 s each. Docker's default is 10 s, which kills running jobs. The Dokploy source has a `stopGracePeriodSwarm` setting, but the docs do not show where it is in the UI **(verify in your Dokploy version)**. If you raise `QUEUE_TIMEOUT`, raise this too: `QUEUE_TIMEOUT` + 90 s.

**Resources** (optional; bytes and nanoCPUs):

| Setting | Suggested |
|---|---|
| Memory limit | `1610612736` (1.5 GiB): web + two workers + scheduler |
| Memory reservation | `536870912` (512 MiB) |
| CPU limit | `1000000000` to `2000000000` (1–2 cores) |

**Replicas: always `1`.** A second replica would run a second scheduler.

---

## 6. Deploy and verify

Click **Deploy**. The Application's **Logs** should show, in order:

```text
{"message":"Running migrations with the migrator connection",...}
{"message":"Release finished",...}
{"message":"All-in-one role started (nginx :8080, php-fpm max_children=10, workers critical and default,low, scheduler)",...}
INFO spawned: 'php-fpm' with pid …
INFO spawned: 'nginx' with pid …
INFO spawned: 'worker-critical' with pid …
INFO spawned: 'worker-default' with pid …
INFO spawned: 'scheduler' with pid …
{"message":"Worker started on queues critical",...}
{"message":"Worker started on queues default,low",...}
INFO success: worker-critical entered RUNNING state …
```

| Check | Expected |
|---|---|
| `curl -sI https://app.<domain>/login` | `200`, `Set-Cookie: axispay_app_session=…; secure; httponly` (no `secure` without TLS) |
| `curl -sI https://admin.<domain>/login` | `200`, cookie `axispay_admin_session` |
| `curl -s -o /dev/null -w '%{http_code}' https://app.<domain>/up` | `200` |
| Page source of `/login` | Asset URLs use the same scheme as the page |
| **Monitoring** | One running container, status healthy |

In the container terminal, `ps -eo user,args` lists `php-fpm`, `nginx`, two `queue:work` processes and `schedule:work`, all as `www-data`.

## 7. Create the first platform admin

Open a terminal in the container (the Application's terminal option **(verify in your Dokploy version)**, or `docker exec -it <container> bash` on the server) and run:

```sh
php artisan axispay:create-platform-admin
```

It is interactive and never takes the password as an argument. Sign in at the admin host and set up 2FA (mandatory for platform admins).

---

## Operating it

### Logs: one stream for every program

Every program writes to the same container log. Tell them apart by the line's shape:

| Line looks like | Comes from |
|---|---|
| `2026-09-24 17:20:10,311 INFO spawned: 'worker-critical' …`, `exited: …`, `stopped: …` | supervisord (process lifecycle) |
| JSON with `"channel":"entrypoint"` and `"role":"all-in-one"` or `"role":"queue-work"` | The entrypoint (start-up, worker start) |
| JSON with `"channel":"production"` | The application: web requests, jobs and scheduled tasks alike (redacted, ADR-0035) |
| `  2026-09-24 17:22:26 App\…\SomeJob …… RUNNING` / `DONE` / `FAIL` | A queue worker |
| `INFO  No scheduled commands are ready to run.` or a scheduled command's name | The scheduler (once a minute) |
| `[24-Sep-2026 17:20:10] NOTICE: …` | php-fpm |
| `2026/09/24 17:19:22 [error] …` | nginx (errors only; access logs are off) |

The worker lines do not say which queue they came from. The job class does: check where it is dispatched.

### One-off commands

Container terminal: `php artisan <command>`, for example `php artisan migrate:status` or `php artisan queue:failed`. The runtime user has no DDL rights; migrations run only through a deploy.

### Account recovery

Lost authenticator or forgotten password, for platform admins and tenant users: from an interactive container terminal (`docker exec -it <container> bash`), after verifying the person's identity out of band:

```sh
php artisan axispay:reset-2fa person@example.com
php artisan axispay:reset-password person@example.com
```

Both ask for a reason (stored in the audit log, never a secret) and a confirmation, sign the account out everywhere and clear its sign-in throttle. The password is only read from a hidden prompt. Details, options and exit codes: [Account recovery](dokploy.md#account-recovery) in the production guide (ADR-0041).

### Redeploy and rollback

| Situation | What happens / what to do |
|---|---|
| Push (Auto Deploy on) or **Deploy** | Build, then stop-first update: the old container stops (jobs finish), the new one migrates and starts. Brief downtime |
| New container never becomes healthy | Swarm rolls back (`FailureAction: rollback`) and starts the previous version again |
| New release is healthy but broken | Revert the commit and deploy, or use Dokploy's registry-based **Rollback** ([rollbacks docs](https://docs.dokploy.com/docs/core/applications/rollbacks)) |
| A migration must be undone | Never automatic: write a forward migration (expand/contract, plan 25.4) |

---

## Local test deployment without TLS

For a Dokploy server on your LAN or in a VM, with names that only your machine resolves and no certificates.

### Pick the names

Use the reserved **`.test`** TLD, for example:

```text
api.axispay.test   app.axispay.test   admin.axispay.test   pay.axispay.test
```

| Do not use | Why |
|---|---|
| `.dev`, `.app` (and other HSTS-preloaded TLDs) | Browsers force HTTPS for the whole TLD; plain HTTP never loads |
| `.local` | Reserved for mDNS; resolution is slow or fails on macOS and Linux |
| A real domain you own | Its HSTS (with `includeSubDomains`) or real DNS can take over |

### Resolve them with the hosts file

On every machine that opens the site, point the four names to the Dokploy server's IP:

| OS | File |
|---|---|
| Linux, macOS | `/etc/hosts` |
| Windows | `C:\Windows\System32\drivers\etc\hosts` (edit as Administrator) |

```text
192.168.1.50  api.axispay.test app.axispay.test admin.axispay.test pay.axispay.test
```

### Configure Dokploy and the variables

1. Domains (step 4): the four `.test` hosts, port `8080`, **HTTPS off**, certificate **None** ([domains docs](https://docs.dokploy.com/docs/core/domains)).
2. Variables (step 3):

   ```dotenv
   APP_URL=http://app.axispay.test
   AXISPAY_API_HOST=api.axispay.test
   AXISPAY_APP_HOST=app.axispay.test
   AXISPAY_ADMIN_HOST=admin.axispay.test
   AXISPAY_PAY_HOST=pay.axispay.test
   SESSION_SECURE_COOKIE=false
   ```

   With `SESSION_SECURE_COOKIE=true` over HTTP, the browser drops the session cookie and every sign-in fails with `419 Page Expired`.

### Caveats over plain HTTP

| Topic | What to expect |
|---|---|
| HSTS header | Nginx always sends `Strict-Transport-Security`. Browsers ignore it over HTTP. But if the same host is ever opened over **HTTPS**, the browser pins HTTPS for that host and its subdomains for a year. Clear it in Chrome at `chrome://net-internals/#hsts` (**Delete domain security policies**); in Firefox, **History → Forget About This Site** |
| "Copy" buttons | The Clipboard API needs a secure context. `http://*.test` is not one (only `localhost` is), so copy buttons may do nothing |
| Stripe.js (Phase 4+) | Test mode allows HTTP; live mode requires HTTPS |
| Cookies | No `secure` flag; fine for test data only, never real data |

### Optional: local HTTPS with mkcert

[mkcert](https://github.com/FiloSottile/mkcert) creates a local certificate authority that your machine trusts.

1. On your machine:

   ```sh
   mkcert -install
   mkcert "*.axispay.test"
   # -> _wildcard.axispay.test.pem and _wildcard.axispay.test-key.pem
   ```

   Other machines trust the certificate only after they install the same CA: copy `rootCA.pem` from `mkcert -CAROOT` and run `mkcert -install` there with `CAROOT` pointing to it.
2. In Dokploy, add a custom certificate ([certificates docs](https://docs.dokploy.com/docs/core/certificates)): **Name** `axispay-test`, **Certificate Data** = contents of `_wildcard.axispay.test.pem`, **Private Key** = contents of `_wildcard.axispay.test-key.pem`. Where the Certificates screen is in the menu **(verify in your Dokploy version)**.
3. On each of the four domains: **HTTPS on**, certificate provider **None**, so Traefik serves the uploaded certificate by host name. The docs show this for `traefik.me` certificates, and note that an uploaded certificate may need Traefik to pick it up **(verify in your Dokploy version)**.
4. Switch the variables to HTTPS: `APP_URL=https://app.axispay.test`, `SESSION_SECURE_COOKIE=true`, and redeploy.

From then on the hosts are HSTS-pinned in your browser; to go back to HTTP, clear them as described above.

---

## Moving to production

Production never runs this role. To promote the setup:

- [ ] Follow [`dokploy.md`](dokploy.md) from the start: four Applications (`web`, `worker-critical`, `worker-default`, `scheduler`) in a **production** environment.
- [ ] External MariaDB server (not a Dokploy database service), with TLS across untrusted networks and a firewall.
- [ ] `CONTAINER_ROLE=web` + `RUN_MIGRATIONS=true` on `web` only; `DB_MIGRATOR_*` on `web` only.
- [ ] Update order: `start-first` for `web` and the workers, `stop-first` for `scheduler`.
- [ ] Stop grace period 90 s on the workers.
- [ ] Real domains, HTTPS with Let's Encrypt, `SESSION_SECURE_COOKIE=true`, `APP_URL=https://…`.
- [ ] A new `APP_KEY` for production, backed up outside Dokploy. Never reuse the staging key or data.
- [ ] Auto Deploy off for production.
- [ ] The [security checklist](dokploy.md#security-checklist).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `419 Page Expired` on sign-in over HTTP | `SESSION_SECURE_COOKIE=true` without TLS: the browser drops the cookie | `SESSION_SECURE_COOKIE=false` (HTTP only) or set up [mkcert](#optional-local-https-with-mkcert) |
| `419` over HTTPS | `TRUSTED_PROXIES` unset or wrong, or hosts do not match | See the [production troubleshooting](dokploy.md#troubleshooting) |
| Any `419` or sign-in problem | Not known yet | Run `php artisan axispay:doctor` in the container terminal (read-only, prints no secret); fix every `ERROR` row |
| `419` on the **second** action of a panel page (second sign-in attempt, 2FA set-up) | A bug in versions before the fix of 2026-09-25 (the panel middleware ran twice on Livewire requests) | Deploy a version that includes the fix (see `CHANGELOG.md`) |
| `419` at random while another Application also serves these hosts | Two Applications (for example this one and the production `web`) have a domain on the same host, with a different `APP_KEY` or database | Keep one Application per host. Compare the `APP_KEY fingerprint` of `axispay:doctor` in both |
| Container exits at boot: `SESSION_DOMAIN must be empty (null)` | `SESSION_DOMAIN` is set (ADR-0034) | Remove the variable |
| `Missing required environment variable …` | A variable is missing | Check the Environment tab |
| Container is unhealthy but the site loads | A worker or the scheduler is not running (supervisord gave up after repeated start failures) | Read the log for `gave up: <program> entered FATAL state` and the error above it (often the database). Swarm replaces the container; fix the cause and redeploy |
| Log shows `exited: worker-… (… not expected)` then `spawned:` | A worker crashed or hit `QUEUE_MEMORY` | supervisord restarts it; investigate if it repeats |
| `pm.min_spare_servers(2) and pm.max_spare_servers(6) cannot be greater than pm.max_children` | `PHP_FPM_MAX_CHILDREN` below 6 | Set it to 6 or more |
| Scheduled tasks run twice around a deploy | Update order `start-first`, or more than one replica | `"Order": "stop-first"` and 1 replica |
| Migrations fail: `CREATE command denied` / `ALTER command denied` | Migrator user missing or without DDL rights | Set `DB_MIGRATOR_*` and check its grants ([1.3](#13-database-users)) |
| Migrations fail with error 1419 on `CREATE TRIGGER` | Binary logging on | See the [production troubleshooting](dokploy.md#troubleshooting) |
| Jobs are retried or duplicated after a deploy; log shows no `stopped: worker-…` | The container was killed before jobs finished (grace period too short, Docker default 10 s) | Stop grace period 150 s (`QUEUE_TIMEOUT` + 90 s) |
| Browser always switches to `https://` for a `.test` host | The host (or its parent) was once opened over HTTPS and is HSTS-pinned | Clear it at `chrome://net-internals/#hsts` or use a different name |
| Page never loads on a `.dev` or `.app` host | Those TLDs are HSTS-preloaded | Use `.test` |
| Someone lost their authenticator or forgot their password | No self-service recovery yet | [Account recovery](#account-recovery): `axispay:reset-2fa` / `axispay:reset-password` |
| "Too many sign-in attempts" for one account | 5 failed attempts in 15 minutes (ADR-0034) | Wait up to 15 minutes; the recovery commands clear that account's lock; `php artisan cache:clear` clears every lock |
| Every 2FA code is rejected | Clock skew on the server or the phone | Enable NTP on the server (`timedatectl`) and automatic time on the phone |
| 2FA and encrypted data fail after changing `APP_KEY` | The old key is gone, so 2FA secrets cannot be decrypted | Never rotate `APP_KEY` without `APP_PREVIOUS_KEYS`. Restore the old key; if it is lost, reset every account's 2FA with `axispay:reset-2fa` |
| `404` for every page on a host | The host differs from its `AXISPAY_*_HOST` value | Make them identical |
