#!/bin/sh
set -eu

test "$#" -eq 1
binary=$1
probe=$(mktemp -d /tmp/artifactflow-jose-tls.XXXXXX)
cat > "$probe/Caddyfile" <<'CADDY'
{
    admin off
    persist_config off
    skip_install_trust
}

localhost:18443 {
    bind 127.0.0.1
    tls internal
    respond "ArtifactFlow JOSE migration TLS probe"
}
CADDY

XDG_DATA_HOME="$probe/data" "$binary" run --adapter caddyfile --config "$probe/Caddyfile" > "$probe/server.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT HUP INT TERM
attempt=0
while [ ! -f "$probe/data/caddy/pki/authorities/local/root.crt" ]; do
    attempt=$((attempt + 1))
    test "$attempt" -le 30
    kill -0 "$server_pid"
    sleep 1
done
curl --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 \
    --connect-timeout 3 --max-time 10 \
    --resolve localhost:18443:127.0.0.1 \
    --noproxy '*' --cacert "$probe/data/caddy/pki/authorities/local/root.crt" \
    https://localhost:18443 > "$probe/response.txt"
grep -Fx 'ArtifactFlow JOSE migration TLS probe' "$probe/response.txt"
echo 'FrankenPHP internal TLS issues a certificate and serves a verified HTTPS response.'
