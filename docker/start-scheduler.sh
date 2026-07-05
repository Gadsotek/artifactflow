#!/bin/sh
set -eu

cd /var/www/html

if [ -x /var/www/html/docker/ensure-vendor.sh ]; then
  /var/www/html/docker/ensure-vendor.sh
  php artisan config:clear >/dev/null 2>&1 || true
else
  php artisan config:cache
fi

exec php artisan schedule:work
