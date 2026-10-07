#!/usr/bin/env bash
# Container entrypoint (ADR-0035). The role is the first argument or, when
# there is none, CONTAINER_ROLE (how Dokploy selects it), else `web`:
#
#   web        Nginx + PHP-FPM on :8080 (default)
#   worker     queue:work on QUEUE_NAMES (default critical,default,low)
#   scheduler  schedule:work (runs schedule:run every minute)
#   all-in-one supervisord running php-fpm, nginx, two workers (critical;
#              default,low) and the scheduler in one container. Staging and
#              local/test servers only (ADR-0039); production uses one
#              container per role (ADR-0036).
#   release    one-shot: wait for the database, migrate with the migrator
#              user (--database=mariadb_migrator), seed the catalog, exit
#   artisan    php artisan <args...> (one-off commands)
#   queue-work <queues>  internal: the queue:work process that supervisord
#              starts in the all-in-one role (no schema wait, no cache warm-up)
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

# The limits that follow AXISPAY_VALIDATION_TIMEOUT_SECONDS (ADR-0061): the
# web server's and PHP-FPM's request limits and the queue worker's default
# timeout. The application computes them (axispay:validation-timeouts), so the
# formulas exist once; the command also refuses an inconsistent setting, which
# stops the container before it serves anything.
derive_validation_timeouts() {
    local derived line
    # A separate assignment: set -e does not see a failure inside eval "$(...)".
    derived="$(php artisan axispay:validation-timeouts --no-interaction)"
    while IFS= read -r line; do
        export "$line"
    done <<< "$derived"

    # An explicit QUEUE_TIMEOUT must still let every job finish (at least the
    # job timeout) and stop before the queue hands the job to another worker.
    if [[ -n "${QUEUE_TIMEOUT:-}" ]] \
        && (( QUEUE_TIMEOUT < AXISPAY_JOB_TIMEOUT_SECONDS || QUEUE_TIMEOUT >= AXISPAY_QUEUE_RETRY_AFTER_SECONDS )); then
        log ERROR "QUEUE_TIMEOUT=${QUEUE_TIMEOUT} must be at least ${AXISPAY_JOB_TIMEOUT_SECONDS} and below ${AXISPAY_QUEUE_RETRY_AFTER_SECONDS} (AXISPAY_VALIDATION_TIMEOUT_SECONDS=${AXISPAY_VALIDATION_TIMEOUT_SECONDS}); unset it to use ${AXISPAY_QUEUE_TIMEOUT_DEFAULT}"
        exit 64
    fi
}

# nginx has no environment variables in its configuration, and /etc/nginx is
# not writable by the container user: the one derived limit goes in a file
# under /tmp that nginx.conf includes.
write_nginx_timeouts() {
    cat > /tmp/axispay-timeouts.conf <<EOF
fastcgi_read_timeout ${AXISPAY_NGINX_FASTCGI_READ_TIMEOUT}s;
EOF
}

# queue:work with the flags shared by the `worker` role and the all-in-one
# programs. SIGTERM (redeploy, scale down): the current job finishes, then the
# process exits. --memory exits with a non-zero status so the orchestrator (or
# supervisord) restarts a fresh process.
run_queue_worker() {
    local queues="$1"
    # Inside the all-in-one container the limits were derived once, before
    # supervisord started.
    if [[ -z "${AXISPAY_QUEUE_TIMEOUT_DEFAULT:-}" ]]; then
        derive_validation_timeouts
    fi
    log INFO "Worker started on queues ${queues}"
    exec php artisan queue:work "${QUEUE_CONNECTION:-database}" \
        --queue="$queues" \
        --sleep="${QUEUE_SLEEP:-3}" \
        --tries="${QUEUE_TRIES:-3}" \
        --timeout="${QUEUE_TIMEOUT:-${AXISPAY_QUEUE_TIMEOUT_DEFAULT}}" \
        --memory="${QUEUE_MEMORY:-192}" \
        --no-interaction
}

# The health check reads the container's role. Processes started inside the
# container (queue-work under supervisord) must not overwrite it.
if [[ "$role" != "queue-work" ]]; then
    echo "$role" > /tmp/axispay-role
fi

case "$role" in
    web)
        require_env APP_KEY
        export PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-10}"
        prepare_schema
        warm_caches
        derive_validation_timeouts
        write_nginx_timeouts

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
        run_queue_worker "${QUEUE_NAMES:-critical,default,low}"
        ;;

    scheduler)
        require_env APP_KEY
        prepare_schema
        warm_caches
        log INFO "Scheduler started (schedule:run every minute)"
        exec php artisan schedule:work --no-interaction
        ;;

    all-in-one)
        require_env APP_KEY
        export PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-10}"
        # supervisord expands these in its config: a worker gets its job's
        # timeout plus a margin before it is killed on stop.
        prepare_schema
        warm_caches
        derive_validation_timeouts
        write_nginx_timeouts
        export QUEUE_TIMEOUT="${QUEUE_TIMEOUT:-${AXISPAY_QUEUE_TIMEOUT_DEFAULT}}"
        export QUEUE_STOP_WAIT_SECONDS=$((QUEUE_TIMEOUT + 15))
        log INFO "All-in-one role started (nginx :8080, php-fpm max_children=${PHP_FPM_MAX_CHILDREN}, workers critical and default,low, scheduler)"
        # supervisord receives SIGTERM from tini and stops the workers and the
        # scheduler first, then nginx, then php-fpm (priorities in the config).
        exec supervisord --nodaemon --configuration /etc/supervisor/supervisord.conf
        ;;

    queue-work)
        run_queue_worker "${1:?queue-work needs a queue list, for example critical}"
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
