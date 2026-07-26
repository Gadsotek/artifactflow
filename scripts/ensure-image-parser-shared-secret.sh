#!/bin/sh
# Shell fallback for scripts/ensure-image-parser-shared-secret.php on hosts without PHP.
# Keep the behavior in sync with the PHP script.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
env_path="${1:-$script_dir/../.env}"

if [ ! -f "$env_path" ]; then
    echo "Environment file does not exist: $env_path" >&2
    exit 1
fi

if grep -Eq '^IMAGE_PARSER_SHARED_SECRET=[[:space:]]*[^[:space:]]' "$env_path"; then
    echo "IMAGE_PARSER_SHARED_SECRET already configured."
    exit 0
fi

if command -v openssl >/dev/null 2>&1; then
    parser_secret="base64:$(openssl rand -base64 32)"
else
    parser_secret="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
fi

if grep -q '^IMAGE_PARSER_SHARED_SECRET=' "$env_path"; then
    tmp_path="$(mktemp)"
    # Pass the secret through the environment, not argv: process arguments are
    # briefly ps-visible to other users on multi-user hosts.
    ARTIFACTFLOW_IMAGE_PARSER_SECRET="$parser_secret" awk '
        /^IMAGE_PARSER_SHARED_SECRET=/ { print "IMAGE_PARSER_SHARED_SECRET=" ENVIRON["ARTIFACTFLOW_IMAGE_PARSER_SECRET"]; next }
        { print }
    ' "$env_path" >"$tmp_path"
    cat "$tmp_path" >"$env_path"
    rm -f "$tmp_path"
else
    if [ -s "$env_path" ] && [ -n "$(tail -c 1 "$env_path")" ]; then
        echo >>"$env_path"
    fi
    printf 'IMAGE_PARSER_SHARED_SECRET=%s\n' "$parser_secret" >>"$env_path"
fi

echo "Generated IMAGE_PARSER_SHARED_SECRET in .env."
