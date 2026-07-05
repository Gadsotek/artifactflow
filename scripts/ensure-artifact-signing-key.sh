#!/bin/sh
# Shell fallback for scripts/ensure-artifact-signing-key.php on hosts without PHP.
# Keep the behavior in sync with the PHP script.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
env_path="${1:-$script_dir/../.env}"

if [ ! -f "$env_path" ]; then
    echo "Environment file does not exist: $env_path" >&2
    exit 1
fi

if grep -Eq '^ARTIFACT_URL_SIGNING_KEY=[[:space:]]*[^[:space:]]' "$env_path"; then
    echo "ARTIFACT_URL_SIGNING_KEY already configured."
    exit 0
fi

if command -v openssl >/dev/null 2>&1; then
    key="base64:$(openssl rand -base64 32)"
else
    key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
fi

if grep -q '^ARTIFACT_URL_SIGNING_KEY=' "$env_path"; then
    tmp_path="$(mktemp)"
    # Pass the key through the environment, not argv: process arguments are
    # briefly ps-visible to other users on multi-user hosts.
    ARTIFACTFLOW_SIGNING_KEY="$key" awk '
        /^ARTIFACT_URL_SIGNING_KEY=/ { print "ARTIFACT_URL_SIGNING_KEY=" ENVIRON["ARTIFACTFLOW_SIGNING_KEY"]; next }
        { print }
    ' "$env_path" >"$tmp_path"
    cat "$tmp_path" >"$env_path"
    rm -f "$tmp_path"
else
    if [ -s "$env_path" ] && [ -n "$(tail -c 1 "$env_path")" ]; then
        echo >>"$env_path"
    fi
    printf 'ARTIFACT_URL_SIGNING_KEY=%s\n' "$key" >>"$env_path"
fi

echo "Generated ARTIFACT_URL_SIGNING_KEY in .env."
