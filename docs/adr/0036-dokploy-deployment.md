# ADR-0036: Deployment on Dokploy with one Application per role

- **Status:** Accepted (Dokploy chosen by the project owner, 2026-09-24; the deployment shape is decided by delegation)
- **Date:** 2026-09-24
- **Source:** master plan sections 4.1, 23, 24, 25.1–25.5; ADR-0026, ADR-0027, ADR-0034, ADR-0035
- **Verified against:** Dokploy documentation (docs.dokploy.com) and source (Dokploy/dokploy v0.30.6), 2026-09-24

## Context

On 2026-09-24 the project owner chose Dokploy, a self-hosted PaaS on Docker Swarm with Traefik, to run staging and production. Plan 25.4 describes a different mechanism: symlink releases with Deployer or Envoy, `queue:restart` and a PHP-FPM reload. This ADR records the deviation and how each section 25 requirement is still met.

These Dokploy facts shape the decision:

- An **Application** is deployed as a Swarm service.
  - Its Update Config defaults to `start-first` with `FailureAction: rollback`, and a health check gates the switch. That gives zero-downtime deploys.
  - An Application can have several domains, each with its own Let's Encrypt certificate.
  - The build types include Dockerfile, and the variables and command can be set per Application.
- A **Docker Compose** service in "Docker Compose" mode runs `docker compose up -d --build`, which recreates containers: there is no rolling update. "Stack" mode does roll, but it cannot build (`build:` is not supported).
- Dokploy has **no pre-deploy or post-deploy hook**. Issue #110 and PR #4240 are still open. The maintainers' guidance is to run migrations from the container's entrypoint, combined with health checks, start-first updates and backward-compatible migrations.
- **Schedule Jobs** exist. They `docker exec` a command into the first running container of a service on a cron expression.
- **Projects contain Environments** (for example staging and production), each with its own variables.

## Options considered

1. **One Dokploy Application per role, all built from the same repository and Dockerfile.** The roles are `web`, `worker-critical`, `worker-default` and `scheduler`. `web` runs the migrations before it starts serving.
2. **One Docker Compose service with every role.** Everything lives in one file, but deploys recreate the web container (downtime), and domains need a redeploy.
3. **A Compose "Stack" fed by a registry image built in CI.** It rolls, but it needs a registry and a CI push pipeline from day one. A one-shot migration task has no ordering guarantee in a stack.
4. **The scheduler as a Dokploy Schedule Job** (`php artisan schedule:run` every minute via `docker exec`). No long-running container, but it execs into whichever container runs first, it fails when that container is being replaced, and it records a log entry every minute.

## Decision

Option 1. There are four Applications per Dokploy environment (staging and production are separate environments of the same project), all running the image from ADR-0035:

| Application | `CONTAINER_ROLE` | Domains | Replicas | Update order | Notes |
|---|---|---|---|---|---|
| `web` | `web` | `api.`, `app.`, `admin.`, `pay.` → port 8080, HTTPS (Let's Encrypt) | 1 at first; 2+ later | start-first, rollback on failure | `RUN_MIGRATIONS=true`: migrates with `axispay_migrator`, then seeds the permission catalog, before serving |
| `worker-critical` | `worker` | none | 1 | start-first | `QUEUE_NAMES=critical` |
| `worker-default` | `worker` | none | 1 | start-first | `QUEUE_NAMES=default,low` |
| `scheduler` | `scheduler` | none | exactly 1 | **stop-first** | `schedule:work`; stop-first avoids two schedulers during a deploy |

- **Build.** Each Application uses the Git provider and the Dockerfile build type (`Dockerfile`, context `.`). Build secrets are not needed: the image holds no configuration.
- **Ordering.** Every Application auto-deploys on push. The workers and the scheduler wait until no migration is pending before they start. They are released when the `web` deploy has migrated.
- **Configuration.**
  - Secrets and settings are Dokploy environment variables.
  - Values shared by the four Applications (for example `APP_KEY` and the database settings) are defined once as Environment variables and referenced with `${{environment.NAME}}`.
  - The migrator credentials are set on `web` only.
- **Health.**
  - The image `HEALTHCHECK` depends on the role.
  - `web` also sets an explicit Swarm health check with a long `StartPeriod`, so migrations can finish before the check counts.
- **Stop grace period.** Set to 90 s for the workers, which is more than `QUEUE_TIMEOUT` (60 s).

## How plan section 25 is met

| Requirement | How | Status |
|---|---|---|
| Nginx + PHP-FPM, no Octane (5, 25.1) | ADR-0035 image | Met |
| ≥ 2 `queue:work` processes on `critical`, `default`, `low` (25.1) | `worker-critical` + `worker-default` (replicas can grow) | Met |
| `schedule:run` every minute (25.1) | `scheduler` Application (`schedule:work`) | Met |
| Separate DB server, TLS across untrusted networks, firewall (25.1) | External MariaDB (ADR-0026), `MYSQL_ATTR_SSL_*`, firewall on the DB server | Met by configuration; the operator applies it |
| Zero-downtime deploy (25.4) | Swarm start-first plus health check on `web` | Met (Dokploy's replacement for symlink releases) |
| `composer install --no-dev --optimize-autoloader`, asset build (25.4) | Image build stages | Met |
| `migrate --force` with the migrator user (25.4, 25.5) | `web` with `RUN_MIGRATIONS=true` → `--database=mariadb_migrator` | Met; see the least-privilege gap below |
| `config:cache`, `route:cache`, `view:cache`, `event:cache` (25.4) | At container start, per container | Met |
| Symlink switch, `queue:restart`, FPM reload (25.4) | Replaced by the rolling update of each service | Deviation (accepted) |
| Expand/contract migrations (25.4) | Still required: old `web` tasks serve during the migration, and old workers run until replaced | Process rule, unchanged |
| Staging automatic, production manual with approval (25.4) | Staging: auto-deploy on push. Production: auto-deploy off, manual Deploy button | Partly met: Dokploy has no approval step; access control is Dokploy permissions |
| DB users `axispay_app` (DML), `axispay_migrator` (DDL) (25.5) | Grants in `docs/deployment/dokploy.md` | Met; `axispay_backup` and `axispay_readonly` belong to the DB server's operator |
| Backups, PITR, restore tests (25.2) | On the external DB server; Dokploy backups only cover Dokploy-managed databases | Outside Dokploy; open item |
| Backups of `APP_KEY`, secrets (23.2, 25.2) | Stored outside Dokploy, separately from DB backups | Operator procedure |
| NTP, SSH hardening, fail2ban (25.1) | Server administration | Outside this repository |
| CI: Pint, Larastan, Pest, `composer audit` (25.4) | `.github/workflows/ci.yml` | Met; secret scanning (gitleaks) is still open (23.2) |

## Consequences

- **The migrator credentials live in the `web` service environment** (least-privilege gap). The entrypoint removes them before PHP-FPM starts and before the config is cached, so PHP processes and `bootstrap/cache/config.php` never hold them. The container's PID 1 environment still does. The stricter alternative is a separate `release` Application (`CONTAINER_ROLE=release`, Swarm mode `ReplicatedJob`) that is deployed before `web`. The image supports it, but it needs a manual or scripted deploy order.
- **Four builds per push.** On one Dokploy server the builds share Docker's layer cache, so the second to fourth builds should mostly reuse it (verify in your Dokploy version). When a container registry is available, the recommended step is to build once in CI, push the image, and switch the four Applications to the Docker provider. Dokploy's registry-based Rollback button also needs a registry.
- **Rollbacks.**
  - A failed health check rolls back automatically through Swarm.
  - A bad release that passes its health check is rolled back by reverting the commit and redeploying, or by the registry-based rollback once a registry is set up.
  - A schema rollback is never automatic. Expand/contract keeps the previous code compatible.
- **Replicas.** Scale `web` above one replica only after the first deploy. A new service with several replicas starts them in parallel, and each would try to migrate. Later deploys update one task at a time.
- **No approval gate** for production in Dokploy. Deploying is restricted to the people who hold deploy rights in Dokploy.
- **Plan 25.4 is superseded** on the symlink-release mechanism only. All other rules of section 25 stand.
- **Staging and local/test servers may use one all-in-one Application instead** ([ADR-0039](0039-all-in-one-container-role.md)); production keeps the four Applications.
- **Admin host allowlist (plan 4.1, recommended).** It is applied as a Traefik `ipAllowList` middleware in the `web` Application's Traefik file. Dokploy rewrites a domain's router when that domain is edited, so the middleware must be re-attached after changing the admin domain.
