#!/usr/bin/env bash
# Role-aware container healthcheck (ADR-0035). The entrypoint records the role.
set -euo pipefail

role="$(cat /tmp/axispay-role 2>/dev/null || echo web)"

web_up() {
    # /up is registered without Route::domain(), so it answers on any Host.
    curl --fail --silent --show-error --max-time 4 --output /dev/null \
        http://127.0.0.1:8080/up
}

running() {
    pgrep -f "$1" >/dev/null
}

case "$role" in
    web)
        web_up
        ;;
    worker)
        running 'artisan queue:work'
        ;;
    scheduler)
        running 'artisan schedule:work'
        ;;
    all-in-one)
        # Healthy only when the web answers and both workers and the
        # scheduler are running (a program supervisord gave up on fails here).
        web_up
        running 'artisan queue:work [^ ]+ --queue=critical( |$)'
        running 'artisan queue:work [^ ]+ --queue=default,low( |$)'
        running 'artisan schedule:work'
        ;;
    *)
        exit 0
        ;;
esac
