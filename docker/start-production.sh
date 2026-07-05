#!/bin/sh
set -eu

cd /var/www/html

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force --isolated
fi

mkdir -p /var/www/html/storage/app/public
mkdir -p "${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/logs

php artisan storage:link --force 2>/dev/null || true

php artisan config:cache
php artisan view:cache

export SERVER_NAME=":${PORT:-8080}"
export CADDY_GLOBAL_OPTIONS="auto_https off"
export FRANKENPHP_CONFIG=""

exec frankenphp run --config /var/www/html/docker/Caddyfile
