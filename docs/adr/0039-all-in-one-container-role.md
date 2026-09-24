# ADR-0039: All-in-one container role for staging and local deployments

- **Status:** Accepted (by the project owner, 2026-09-24)
- **Date:** 2026-09-24
- **Source:** master plan sections 24.4, 25.1, 25.3, 25.4; [ADR-0035](0035-production-container-image.md), [ADR-0036](0036-dokploy-deployment.md)

## Context

ADR-0036 deploys AxisPay on Dokploy as four Applications per environment (`web`, `worker-critical`, `worker-default`, `scheduler`). On 2026-09-24 the project owner asked for a simpler shape for staging and for local or test Dokploy servers: one Application, one container, one deploy, and a way to try the deployment on a local server without TLS. Production must keep the four Applications.

## Options considered

1. **A single container for every environment**, production included. Least to operate, but it loses independent restarts and health per role, needs stop-first updates for the whole web (the scheduler must never run twice), shares CPU and memory between requests and jobs, and mixes every log into one stream.
2. **Four Applications everywhere**, staging and test servers included. One shape, but four Applications, four builds and four sets of variables for environments where none of that isolation matters.
3. **Both**: an `all-in-one` role in the same image for staging and local/test servers, four Applications for production.

## Decision

Option 3.

- **New role `all-in-one`** in the existing image (`CONTAINER_ROLE=all-in-one`). It runs the same pre-start steps as `web` (required variables, migrations with the migrator connection when `RUN_MIGRATIONS=true`, removal of the migrator credentials from the environment, cache warm-up), then `exec`s supervisord.
- **Supervisor** (Debian package) is installed in the runtime stage. `docker/app/supervisord.conf` runs, as `www-data`:
  - `php-fpm` and `nginx`, the same commands as the `web` role;
  - `worker-critical` (`--queue=critical`) and `worker-default` (`--queue=default,low`), started through the entrypoint's internal `queue-work` subcommand, which builds the `queue:work` command with the same function as the `worker` role;
  - `scheduler` (`schedule:work`).
- **Signals and order.** `tini` forwards SIGTERM to supervisord. The workers and the scheduler (one group, stopped in parallel, `TERM`, `stopwaitsecs` = `QUEUE_TIMEOUT` + 15 s) stop first, then nginx (`QUIT`), then php-fpm (`QUIT`). Every program logs to the container's stdout/stderr; supervisord has no control socket.
- **Health.** The container is healthy only when `/up` answers and both workers and the scheduler are running.
- **Dokploy settings** for the all-in-one Application: update order **stop-first**, one replica, stop grace period 150 s. The guide is [`deployment/dokploy-all-in-one.md`](../deployment/dokploy-all-in-one.md).
- **Production is unchanged** (ADR-0036).

## Consequences

- **Supervisor is in every image**, including the one production runs, but only the `all-in-one` role starts it. The image grows by about 58 MB on disk (900 MB → 958 MB; Python 3 and supervisor).
- **Scheduled tasks rule.** Every scheduled task must use `withoutOverlapping()` and `onOneServer()` with the shared `database` cache store, so a second scheduler (a start-first update, an extra replica, a production deploy overlapping) never runs a task twice. There are no scheduled tasks yet; the rule is in `docs/development.md`.
- **Health check semantics differ by role.** In `all-in-one`, a dead worker makes the whole container unhealthy, and Swarm replaces it, web included. supervisord restarts a crashed program first; the health check only fails while a program is down or after supervisord gives up on it.
- **Deploys have a short downtime** (stop-first) in this role. Acceptable for staging and test servers only.
- **One log stream.** Lines are told apart by their shape (supervisord, entrypoint JSON, application JSON, worker and scheduler output); the application's JSON lines do not name the process.
- **Resources are shared.** A heavy job can slow web requests; staging performance does not predict production.
- **The migrator credentials** stay in PID 1's environment, as in the `web` role (ADR-0036); supervisord and its programs never receive them.
