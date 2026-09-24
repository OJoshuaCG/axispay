#!/usr/bin/env bash
# Role-aware container healthcheck (ADR-0035). The entrypoint records the role.
set -euo pipefail

role="$(cat /tmp/axispay-role 2>/dev/null || echo web)"

case "$role" in
    web)
        # /up is registered without Route::domain(), so it answers on any Host.
        exec curl --fail --silent --show-error --max-time 4 --output /dev/null \
            http://127.0.0.1:8080/up
        ;;
    worker)
        exec pgrep -f 'artisan queue:work' >/dev/null
        ;;
    scheduler)
        exec pgrep -f 'artisan schedule:work' >/dev/null
        ;;
    *)
        exit 0
        ;;
esac
