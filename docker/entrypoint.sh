#!/bin/bash
set -euo pipefail

cd /app

# Caches are built from the container's environment, so they are rebuilt on every start.
php artisan optimize
php artisan filament:optimize

case "${CONTAINER_ROLE:-app}" in
    app)
        php artisan migrate --force
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    worker)
        exec php artisan queue:work --tries=3 --timeout=900
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "Unknown CONTAINER_ROLE '${CONTAINER_ROLE}'" >&2
        exit 1
        ;;
esac
