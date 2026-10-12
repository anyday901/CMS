#!/bin/sh
set -e

cd /var/www/html

# Settings come from the environment (docker compose reads .env), so cache them now.
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet

if [ "${MIGRATE_ON_START:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
