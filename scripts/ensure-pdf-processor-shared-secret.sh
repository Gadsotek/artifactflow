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

pdf_processor_secret_is_strong() {
    candidate="$(printf '%s' "$1" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")"

    if [ -z "$candidate" ]; then
        return 1
    fi

    lowercase="$(printf '%s' "$candidate" | tr '[:upper:]' '[:lower:]')"
    case "$lowercase" in
        *replace-with*|*replace_me*|*replace-me*|*change-me*|*changeme*|*placeholder*)
            return 1
            ;;
    esac

    if [ "$candidate" = 'artifactflow-local-pdf-processor-secret-not-for-production' ]; then
        return 1
    fi

    case "$candidate" in
        base64:*)
            payload="${candidate#base64:}"

            if ! printf '%s' "$payload" | grep -Eq '^[A-Za-z0-9+/]*={0,2}$'; then
                return 1
            fi

            decoded_path="$(mktemp)"
            decoded=false

            if printf '%s' "$payload" | base64 -d >"$decoded_path" 2>/dev/null; then
                decoded=true
            elif printf '%s' "$payload" | base64 -D >"$decoded_path" 2>/dev/null; then
                decoded=true
            elif command -v openssl >/dev/null 2>&1 \
                && printf '%s' "$payload" | openssl base64 -d -A >"$decoded_path" 2>/dev/null; then
                decoded=true
            fi

            decoded_bytes="$(wc -c <"$decoded_path" | tr -d '[:space:]')"
            rm -f "$decoded_path"

            [ "$decoded" = true ] && [ "$decoded_bytes" -ge 32 ]
            ;;
        *)
            candidate_bytes="$(LC_ALL=C printf '%s' "$candidate" | wc -c | tr -d '[:space:]')"
            [ "$candidate_bytes" -ge 32 ]
            ;;
    esac
}

configured_pdf_processor_secret="$(sed -n 's/^PDF_PROCESSOR_SHARED_SECRET=//p' "$env_path" | head -n 1)"

if pdf_processor_secret_is_strong "$configured_pdf_processor_secret"; then
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
