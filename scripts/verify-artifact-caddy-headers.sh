#!/bin/sh
set -eu

image="${1:-artifactflow-app:production}"
repository_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
probe_root="$(mktemp -d "${TMPDIR:-/tmp}/artifactflow-caddy-headers.XXXXXX")"
normal_container="artifactflow-caddy-normal-$$"
app_container="artifactflow-caddy-app-$$"
error_container="artifactflow-caddy-error-$$"

cleanup() {
    trap - EXIT INT TERM

    for container in "$normal_container" "$app_container" "$error_container"; do
        if docker inspect "$container" >/dev/null 2>&1; then
            docker stop --time 2 "$container" >/dev/null 2>&1 || true
        fi
    done

    rm -f "$probe_root"/*.headers
    rmdir "$probe_root" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

start_server() {
    container="$1"
    config="$2"
    shift 2

    docker run --detach --rm --user root \
        --name "$container" \
        --publish 127.0.0.1::8080 \
        --env SERVER_NAME=:8080 \
        --env "CADDY_GLOBAL_OPTIONS=auto_https off" \
        --entrypoint frankenphp \
        "$@" \
        "$image" run --config "$config" >/dev/null
}

server_url() {
    container="$1"
    attempts=0

    while [ "$attempts" -lt 30 ]; do
        mapping="$(docker port "$container" 8080/tcp 2>/dev/null || true)"
        if [ -n "$mapping" ]; then
            port="${mapping##*:}"
            if curl --silent --connect-timeout 1 --max-time 2 \
                --output /dev/null "http://127.0.0.1:${port}/"; then
                printf 'http://127.0.0.1:%s\n' "$port"

                return 0
            fi
        fi

        attempts=$((attempts + 1))
        sleep 1
    done

    docker logs "$container" >&2 || true
    echo "Caddy security-header probe did not become ready." >&2

    return 1
}

assert_sandboxed_response() {
    label="$1"
    url="$2"
    expected_status="$3"
    headers="$probe_root/$label.headers"
    actual_status="$(curl --silent --show-error \
        --dump-header "$headers" \
        --output /dev/null \
        --write-out '%{http_code}' \
        "$url")"

    if [ "$actual_status" != "$expected_status" ]; then
        echo "$label returned HTTP $actual_status; expected $expected_status." >&2
        sed -n '1,30p' "$headers" >&2

        return 1
    fi

    policy="$(tr -d '\r' < "$headers" | sed -n 's/^Content-Security-Policy: //p')"
    if [ "$policy" != "default-src 'none'; sandbox" ]; then
        echo "$label returned an unexpected Content-Security-Policy: ${policy:-<missing>}" >&2
        sed -n '1,30p' "$headers" >&2

        return 1
    fi
}

assert_cookie_absent() {
    label="$1"
    headers="$probe_root/$label.headers"

    if grep -qi '^Set-Cookie:' "$headers"; then
        echo "$label unexpectedly returned a cookie header." >&2
        sed -n '1,30p' "$headers" >&2

        return 1
    fi
}

assert_cookie_present() {
    label="$1"
    headers="$probe_root/$label.headers"

    if ! grep -qi '^Set-Cookie: artifactflow_edge_probe=present' "$headers"; then
        echo "$label did not preserve the app-role cookie header." >&2
        sed -n '1,30p' "$headers" >&2

        return 1
    fi
}

cookie_probe="$repository_root/tests/Fixtures/Caddy/cookie-probe.php"

start_server \
    "$normal_container" \
    /var/www/html/docker/Caddyfile \
    --env APP_RUNTIME_ROLE=artifact-host \
    --volume "$cookie_probe:/var/www/html/public/cookie-probe.php:ro"
normal_url="$(server_url "$normal_container")"
assert_sandboxed_response static-file "$normal_url/favicon.svg" 200
assert_sandboxed_response storage-denial "$normal_url/storage/probe" 404
assert_sandboxed_response artifact-cookie-strip "$normal_url/cookie-probe.php" 204
assert_cookie_absent artifact-cookie-strip
docker stop --time 2 "$normal_container" >/dev/null

start_server \
    "$app_container" \
    /var/www/html/docker/Caddyfile \
    --env APP_RUNTIME_ROLE=app \
    --volume "$cookie_probe:/var/www/html/public/cookie-probe.php:ro"
app_url="$(server_url "$app_container")"
assert_sandboxed_response app-cookie-preserved "$app_url/cookie-probe.php" 204
assert_cookie_present app-cookie-preserved
docker stop --time 2 "$app_container" >/dev/null

start_server \
    "$error_container" \
    /tmp/security-error.Caddyfile \
    --env APP_RUNTIME_ROLE=artifact-host \
    --volume "$repository_root/tests/Fixtures/Caddy/security-error.Caddyfile:/tmp/security-error.Caddyfile:ro"
error_url="$(server_url "$error_container")"
assert_sandboxed_response handler-error "$error_url/probe" 413
assert_cookie_absent handler-error

echo "Artifact Caddy sandbox header probe passed."
