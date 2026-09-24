#!/usr/bin/env bash
# Container entrypoint (ADR-0035). The role is the first argument or, when
# there is none, CONTAINER_ROLE (how Dokploy selects it), else `web`:
#
#   web        Nginx + PHP-FPM on :8080 (default)
#   worker     queue:work on QUEUE_NAMES (default critical,default,low)
#   scheduler  schedule:work (runs schedule:run every minute)
#   release    one-shot: wait for the database, migrate with the migrator
#              user (--database=mariadb_migrator), seed the catalog, exit
#   artisan    php artisan <args...> (one-off commands)
#
# Anything else is executed as is. tini is PID 1 and forwards signals.
#
# Migration ordering for long-running roles:
#   RUN_MIGRATIONS=true   run the release steps first (Dokploy: web app only),
#                         then drop the migrator credentials from the
#                         environment before the runtime starts.
#   otherwise             wait until no migration is pending (up to
#                         MIGRATIONS_WAIT_SECONDS), so new code never runs
#                         against an old schema. WAIT_FOR_MIGRATIONS=false
#                         skips the wait.
set -Eeuo pipefail

cd /var/www/html

if [[ $# -gt 0 ]]; then
    role="$1"
    shift
else
    role="${CONTAINER_ROLE:-web}"
fi

log() {
    # One JSON line on stderr, like the application's own logs.
    printf '{"message":"%s","context":{"role":"%s"},"level_name":"%s","channel":"entrypoint","datetime":"%s"}\n' \
        "$2" "$role" "$1" "$(date -u +%Y-%m-%dT%H:%M:%S.%6NZ)" >&2
}

require_env() {
    local name missing=0
    for name in "$@"; do
        if [[ -z "${!name:-}" ]]; then
            log ERROR "Missing required environment variable ${name}"
            missing=1
        fi
    done
    if [[ $missing -ne 0 ]]; then exit 64; fi
}

# These caches depend on the environment, so they are built at start, never at
# image build time. Each container has its own copy (no shared volume).
warm_caches() {
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
    php artisan event:cache --no-interaction
}

wait_for_database() {
    local connection="$1" deadline=$((SECONDS + ${DB_WAIT_SECONDS:-60}))
    until php artisan db:show --database="$connection" --no-interaction >/dev/null 2>&1; do
        if (( SECONDS >= deadline )); then
            log ERROR "Database connection ${connection} is not reachable"
            return 1
        fi
        sleep 3
    done
}

release() {
    require_env DB_MIGRATOR_USERNAME DB_MIGRATOR_PASSWORD
    wait_for_database mariadb_migrator
    log INFO "Running migrations with the migrator connection"
    php artisan migrate --force --database=mariadb_migrator --no-interaction
    if [[ "${RELEASE_SEED:-true}" == "true" ]]; then
        # Idempotent reference data (permission catalog, system roles),
        # written with the runtime user (DML only).
        php artisan db:seed --force --no-interaction
    fi
    log INFO "Release finished"
}

wait_for_migrations() {
    local deadline=$((SECONDS + ${MIGRATIONS_WAIT_SECONDS:-300}))
    wait_for_database mariadb
    # --pending=1 exits with 1 while a migration is pending (or the
    # migrations table does not exist yet).
    until php artisan migrate:status --pending=1 --no-interaction >/dev/null 2>&1; do
        if (( SECONDS >= deadline )); then
            log ERROR "Migrations are still pending; is the release (web app with RUN_MIGRATIONS=true) running?"
            return 1
        fi
        sleep 5
    done
}

# Runs before any runtime cache is built, so the cached config never holds
# the migrator credentials.
prepare_schema() {
    if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
        release
    elif [[ "${WAIT_FOR_MIGRATIONS:-true}" == "true" ]]; then
        wait_for_migrations
    fi
    unset DB_MIGRATOR_USERNAME DB_MIGRATOR_PASSWORD DB_MIGRATOR_URL
}

echo "$role" > /tmp/paylink-role

case "$role" in
    web)
        require_env APP_KEY
        export PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-10}"
        prepare_schema
        warm_caches

        php-fpm --nodaemonize &
        fpm_pid=$!
        nginx -g 'daemon off;' &
        nginx_pid=$!
        log INFO "Web role started (nginx :8080, php-fpm max_children=${PHP_FPM_MAX_CHILDREN})"

        # Graceful stop: nginx stops accepting connections and drains; FPM
        # finishes running requests (process_control_timeout).
        stopping=0
        trap 'stopping=1; kill -QUIT "$nginx_pid" "$fpm_pid" 2>/dev/null || true' TERM INT QUIT

        set +e
        wait -n "$fpm_pid" "$nginx_pid"
        status=$?
        if [[ $stopping -eq 0 ]]; then
            log ERROR "A web process exited (status ${status}); stopping the container"
            kill -TERM "$nginx_pid" "$fpm_pid" 2>/dev/null
        fi
        wait "$fpm_pid" "$nginx_pid"
        if [[ $stopping -eq 1 ]]; then exit 0; fi
        exit "${status:-1}"
        ;;

    worker)
        require_env APP_KEY
        prepare_schema
        warm_caches
        queues="${QUEUE_NAMES:-critical,default,low}"
        log INFO "Worker started on queues ${queues}"
        # SIGTERM (redeploy, scale down): the current job finishes, then the
        # process exits. --memory exits with a non-zero status so the
        # orchestrator restarts a fresh process.
        exec php artisan queue:work "${QUEUE_CONNECTION:-database}" \
            --queue="$queues" \
            --sleep="${QUEUE_SLEEP:-3}" \
            --tries="${QUEUE_TRIES:-3}" \
            --timeout="${QUEUE_TIMEOUT:-60}" \
            --memory="${QUEUE_MEMORY:-192}" \
            --no-interaction
        ;;

    scheduler)
        require_env APP_KEY
        prepare_schema
        warm_caches
        log INFO "Scheduler started (schedule:run every minute)"
        exec php artisan schedule:work --no-interaction
        ;;

    release)
        require_env APP_KEY
        release
        ;;

    artisan)
        exec php artisan "$@"
        ;;

    *)
        exec "$role" "$@"
        ;;
esac
