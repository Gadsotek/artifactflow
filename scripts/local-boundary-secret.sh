#!/bin/sh
# Shared POSIX-shell fallback for local boundary-secret provisioning.

local_boundary_environment_value() {
    local_boundary_value="$(printf '%s' "$1" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"

    case "$local_boundary_value" in
        \'*)
            if ! printf '%s' "$local_boundary_value" | grep -Eq "^'[^']*'[[:space:]]*(#.*)?$"; then
                return 1
            fi

            printf '%s' "$local_boundary_value" | sed -e "s/^'\([^']*\)'.*$/\1/"
            ;;
        \"*)
            if ! printf '%s' "$local_boundary_value" | grep -Eq '^"[^"\\]*"[[:space:]]*(#.*)?$'; then
                return 1
            fi

            local_boundary_candidate="$(printf '%s' "$local_boundary_value" | sed -e 's/^"\([^"\\]*\)".*$/\1/')"

            case "$local_boundary_candidate" in
                *'$'*) return 1 ;;
            esac

            printf '%s' "$local_boundary_candidate"
            ;;
        *)
            local_boundary_value="$(printf '%s' "$local_boundary_value" | sed -e 's/[[:space:]][[:space:]]*#.*$//' -e 's/[[:space:]]*$//')"

            case "$local_boundary_value" in
                ''|\#*|*'$'*) return 1 ;;
            esac

            printf '%s' "$local_boundary_value"
            ;;
    esac
}

local_boundary_effective_value() {
    local_boundary_key="$1"
    local_boundary_env_path="$2"
    local_boundary_raw="$(sed -n "s/^${local_boundary_key}=//p" "$local_boundary_env_path" | tail -n 1)"
    local_boundary_environment_value "$local_boundary_raw"
}

local_boundary_decode_base64() {
    local_boundary_payload="$1"
    local_boundary_output="$2"

    if printf '%s' "$local_boundary_payload" | base64 -d >"$local_boundary_output" 2>/dev/null; then
        return 0
    fi

    if printf '%s' "$local_boundary_payload" | base64 -D >"$local_boundary_output" 2>/dev/null; then
        return 0
    fi

    command -v openssl >/dev/null 2>&1 \
        && printf '%s' "$local_boundary_payload" | openssl base64 -d -A >"$local_boundary_output" 2>/dev/null
}

local_boundary_normalized_to_file() {
    local_boundary_candidate="$1"
    local_boundary_output="$2"

    case "$local_boundary_candidate" in
        base64:*) local_boundary_decode_base64 "${local_boundary_candidate#base64:}" "$local_boundary_output" ;;
        *) printf '%s' "$local_boundary_candidate" >"$local_boundary_output" ;;
    esac
}

local_boundary_secret_is_strong() {
    local_boundary_candidate="$1"

    if [ -z "$local_boundary_candidate" ]; then
        return 1
    fi

    local_boundary_lowercase="$(printf '%s' "$local_boundary_candidate" | tr '[:upper:]' '[:lower:]')"
    case "$local_boundary_lowercase" in
        *replace-with*|*replace_me*|*replace-me*|*change-me*|*changeme*|*placeholder*) return 1 ;;
    esac

    case "$local_boundary_candidate" in
        artifactflow-local-parser-secret-not-for-production|artifactflow-local-pdf-processor-secret-not-for-production|artifact-preview-test-signing-key)
            return 1
            ;;
        base64:*)
            local_boundary_payload="${local_boundary_candidate#base64:}"

            if ! printf '%s' "$local_boundary_payload" | grep -Eq '^[A-Za-z0-9+/]*={0,2}$'; then
                return 1
            fi

            local_boundary_payload_bytes="$(LC_ALL=C printf '%s' "$local_boundary_payload" | wc -c | tr -d '[:space:]')"
            if [ $((local_boundary_payload_bytes % 4)) -ne 0 ]; then
                return 1
            fi

            local_boundary_decoded_path="$(mktemp)"
            local_boundary_canonical_path="$(mktemp)"
            if ! local_boundary_decode_base64 "$local_boundary_payload" "$local_boundary_decoded_path"; then
                rm -f "$local_boundary_decoded_path" "$local_boundary_canonical_path"
                return 1
            fi

            local_boundary_decoded_bytes="$(wc -c <"$local_boundary_decoded_path" | tr -d '[:space:]')"
            if command -v openssl >/dev/null 2>&1; then
                openssl base64 -A <"$local_boundary_decoded_path" >"$local_boundary_canonical_path"
            else
                base64 <"$local_boundary_decoded_path" | tr -d '\n' >"$local_boundary_canonical_path"
            fi

            local_boundary_decoded_fixture=false
            local_boundary_fixture_path="$(mktemp)"
            for local_boundary_fixture in \
                'artifactflow-local-parser-secret-not-for-production' \
                'artifactflow-local-pdf-processor-secret-not-for-production' \
                'artifact-preview-test-signing-key' \
                'artifactflow-e2e-app-key-0000000'; do
                printf '%s' "$local_boundary_fixture" >"$local_boundary_fixture_path"
                if cmp -s "$local_boundary_decoded_path" "$local_boundary_fixture_path"; then
                    local_boundary_decoded_fixture=true
                fi
            done

            local_boundary_canonical=false
            if [ "$(cat "$local_boundary_canonical_path")" = "$local_boundary_payload" ]; then
                local_boundary_canonical=true
            fi

            rm -f "$local_boundary_decoded_path" "$local_boundary_canonical_path" "$local_boundary_fixture_path"

            [ "$local_boundary_decoded_bytes" -ge 32 ] \
                && [ "$local_boundary_canonical" = true ] \
                && [ "$local_boundary_decoded_fixture" = false ]
            ;;
        *)
            local_boundary_candidate_bytes="$(LC_ALL=C printf '%s' "$local_boundary_candidate" | wc -c | tr -d '[:space:]')"
            [ "$local_boundary_candidate_bytes" -ge 32 ]
            ;;
    esac
}

local_boundary_secrets_match() {
    local_boundary_first_path="$(mktemp)"
    local_boundary_second_path="$(mktemp)"

    if ! local_boundary_normalized_to_file "$1" "$local_boundary_first_path" \
        || ! local_boundary_normalized_to_file "$2" "$local_boundary_second_path"; then
        rm -f "$local_boundary_first_path" "$local_boundary_second_path"
        return 1
    fi

    if cmp -s "$local_boundary_first_path" "$local_boundary_second_path"; then
        rm -f "$local_boundary_first_path" "$local_boundary_second_path"
        return 0
    fi

    rm -f "$local_boundary_first_path" "$local_boundary_second_path"
    return 1
}

ensure_local_boundary_secret() {
    local_boundary_env_path="$1"
    local_boundary_key="$2"
    shift 2

    if [ ! -f "$local_boundary_env_path" ]; then
        echo "Environment file does not exist: $local_boundary_env_path" >&2
        return 1
    fi

    local_boundary_configured=''
    local_boundary_dedicated=true
    if local_boundary_configured="$(local_boundary_effective_value "$local_boundary_key" "$local_boundary_env_path")" \
        && local_boundary_secret_is_strong "$local_boundary_configured"; then
        for local_boundary_other_key in "$@"; do
            local_boundary_other=''
            if local_boundary_other="$(local_boundary_effective_value "$local_boundary_other_key" "$local_boundary_env_path")" \
                && [ -n "$local_boundary_other" ] \
                && local_boundary_secrets_match "$local_boundary_configured" "$local_boundary_other"; then
                local_boundary_dedicated=false
                break
            fi
        done

        if [ "$local_boundary_dedicated" = true ]; then
            echo "$local_boundary_key already configured."
            return 0
        fi
    fi

    if command -v openssl >/dev/null 2>&1; then
        local_boundary_generated="base64:$(openssl rand -base64 32)"
    else
        local_boundary_generated="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    fi

    if grep -q "^${local_boundary_key}=" "$local_boundary_env_path"; then
        local_boundary_tmp_path="$(mktemp)"
        ARTIFACTFLOW_LOCAL_BOUNDARY_KEY="$local_boundary_key" \
        ARTIFACTFLOW_LOCAL_BOUNDARY_SECRET="$local_boundary_generated" awk '
            index($0, ENVIRON["ARTIFACTFLOW_LOCAL_BOUNDARY_KEY"] "=") == 1 {
                print ENVIRON["ARTIFACTFLOW_LOCAL_BOUNDARY_KEY"] "=" ENVIRON["ARTIFACTFLOW_LOCAL_BOUNDARY_SECRET"]
                next
            }
            { print }
        ' "$local_boundary_env_path" >"$local_boundary_tmp_path"
        cat "$local_boundary_tmp_path" >"$local_boundary_env_path"
        rm -f "$local_boundary_tmp_path"
    else
        if [ -s "$local_boundary_env_path" ] && [ -n "$(tail -c 1 "$local_boundary_env_path")" ]; then
            echo >>"$local_boundary_env_path"
        fi
        printf '%s=%s\n' "$local_boundary_key" "$local_boundary_generated" >>"$local_boundary_env_path"
    fi

    echo "Generated $local_boundary_key in .env."
}
