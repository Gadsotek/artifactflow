#!/bin/sh
set -eu

cd /var/www/html

artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/e2e_private_artifacts}"
mkdir -p "${artifact_root}" 2>/dev/null || true
if ! touch "${artifact_root}/.writable-check" 2>/dev/null; then
    echo "FATAL: artifact storage root ${artifact_root} is not writable by uid $(id -u)." >&2
    exit 1
fi
rm -f "${artifact_root}/.writable-check"

/var/www/html/docker/ensure-vendor.sh
php artisan config:clear >/dev/null 2>&1 || true

normal_pid=""
turnstile_pid=""
turnstile_app_port="${E2E_TURNSTILE_APP_PORT:-18182}"

stop_servers() {
    trap - EXIT INT TERM

    if [ -n "${normal_pid}" ]; then
        kill "${normal_pid}" 2>/dev/null || true
    fi
    if [ -n "${turnstile_pid}" ]; then
        kill "${turnstile_pid}" 2>/dev/null || true
    fi

    wait "${normal_pid}" 2>/dev/null || true
    wait "${turnstile_pid}" 2>/dev/null || true
}

trap stop_servers EXIT INT TERM

php artisan serve --host=0.0.0.0 --port=8000 --no-reload &
normal_pid=$!

(
    export APP_URL="http://localhost:${turnstile_app_port}"
    export APP_HOST_PORT="${turnstile_app_port}"
    export ARTIFACT_FRAME_ANCESTORS="http://localhost:${turnstile_app_port}"
    export TURNSTILE_SITE_KEY="1x00000000000000000000AA"
    export TURNSTILE_SECRET_KEY="1x0000000000000000000000000000000AA"
    export TURNSTILE_EXPECTED_HOSTNAME="localhost"

    exec php artisan serve --host=0.0.0.0 --port=8001 --no-reload
) &
turnstile_pid=$!

while kill -0 "${normal_pid}" 2>/dev/null && kill -0 "${turnstile_pid}" 2>/dev/null; do
    sleep 1
done

echo "FATAL: an e2e application server exited unexpectedly." >&2
exit 1
