#!/bin/sh
set -eu

cd /var/www/html

# Fail loudly if the artifact storage root is not writable (for example a named
# volume whose mountpoint was created root-owned). Without this check every
# artifact save surfaces only as an opaque HTTP 500 deep inside a test run.
artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"
mkdir -p "${artifact_root}" 2>/dev/null || true
if ! touch "${artifact_root}/.writable-check" 2>/dev/null; then
  echo "FATAL: artifact storage root ${artifact_root} is not writable by uid $(id -u)." >&2
  echo "If it is a Docker named volume, remove it and recreate it from an image that contains the directory." >&2
  exit 1
fi
rm -f "${artifact_root}/.writable-check"

/var/www/html/docker/ensure-vendor.sh

# First-boot APP_KEY: generate one if .env has no key yet, so `make up` yields a
# working local app without a manual key:generate step. `php artisan serve`
# caches the environment for its whole lifetime, so the key must exist BEFORE the
# server starts -- a key written afterwards (e.g. by artifactflow:install) never
# reaches the already-running process. Never regenerate an existing key: that
# would invalidate already-encrypted data and active sessions.
if ! grep -qE '^APP_KEY=.+$' .env 2>/dev/null; then
  php artisan key:generate --force >/dev/null 2>&1 || echo "WARN: could not generate APP_KEY on first boot." >&2
fi

php artisan config:clear >/dev/null 2>&1 || true

# Preserve Docker Compose environment overrides such as APP_RUNTIME_ROLE in the PHP server child process.
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
