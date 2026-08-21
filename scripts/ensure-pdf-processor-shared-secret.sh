#!/bin/sh
# Shell fallback for scripts/ensure-pdf-processor-shared-secret.php on hosts without PHP.
# Keep the behavior in sync with the PHP script.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
env_path="${1:-$script_dir/../.env}"

if [ ! -f "$env_path" ]; then
    echo "Environment file does not exist: $env_path" >&2
    exit 1
fi

if grep -Eq '^PDF_PROCESSOR_SHARED_SECRET=[[:space:]]*[^[:space:]]' "$env_path"; then
    echo "PDF_PROCESSOR_SHARED_SECRET already configured."
    exit 0
fi

if command -v openssl >/dev/null 2>&1; then
    processor_secret="base64:$(openssl rand -base64 32)"
else
    processor_secret="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
fi

if grep -q '^PDF_PROCESSOR_SHARED_SECRET=' "$env_path"; then
    tmp_path="$(mktemp)"
    # Pass the secret through the environment, not argv: process arguments are
    # briefly ps-visible to other users on multi-user hosts.
    ARTIFACTFLOW_PDF_PROCESSOR_SECRET="$processor_secret" awk '
        /^PDF_PROCESSOR_SHARED_SECRET=/ { print "PDF_PROCESSOR_SHARED_SECRET=" ENVIRON["ARTIFACTFLOW_PDF_PROCESSOR_SECRET"]; next }
        { print }
    ' "$env_path" >"$tmp_path"
    cat "$tmp_path" >"$env_path"
    rm -f "$tmp_path"
else
    if [ -s "$env_path" ] && [ -n "$(tail -c 1 "$env_path")" ]; then
        echo >>"$env_path"
    fi
    printf 'PDF_PROCESSOR_SHARED_SECRET=%s\n' "$processor_secret" >>"$env_path"
fi

echo "Generated PDF_PROCESSOR_SHARED_SECRET in .env."
