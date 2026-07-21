#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    cat <<'USAGE'
Usage: scripts/restore.sh [--dry-run] [--force] [--upgrade-legacy-manifest] <backup-directory>

Restores postgres.dump and artifacts.tar.gz from a backup directory.
Restoring over non-empty state requires --force and typing RESTORE.
Pre-hash ArtifactFlow manifests require the explicit --upgrade-legacy-manifest flag.
USAGE
}

dry_run=0
force=0
upgrade_legacy_manifest=0
backup_dir=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            dry_run=1
            shift
            ;;
        --force)
            force=1
            shift
            ;;
        --upgrade-legacy-manifest)
            upgrade_legacy_manifest=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            if [[ -n "$backup_dir" ]]; then
                printf 'Unexpected restore argument: %s\n' "$1" >&2
                usage >&2
                exit 1
            fi
            backup_dir="$1"
            shift
            ;;
    esac
done

if [[ -z "$backup_dir" && "$dry_run" -eq 1 ]]; then
    backup_dir="backups/<timestamp>"
fi

if [[ -z "$backup_dir" ]]; then
    printf 'A backup directory is required.\n' >&2
    usage >&2
    exit 1
fi

postgres_dump="${backup_dir%/}/postgres.dump"
artifacts_archive="${backup_dir%/}/artifacts.tar.gz"
manifest_path="${backup_dir%/}/manifest.json"

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{ print $1 }'

        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{ print $1 }'

        return
    fi

    printf 'Neither sha256sum nor shasum is available to verify backup files.\n' >&2
    exit 1
}

manifest_hash() {
    local key="$1"

    tr ',' '\n' < "$manifest_path" \
        | sed -nE 's/^[[:space:]]*[{]?[[:space:]]*"'"$key"'"[[:space:]]*:[[:space:]]*"([0-9a-f]{64})"[[:space:]]*[}]?[[:space:]]*$/\1/p'
}

manifest_string() {
    local key="$1"

    tr ',' '\n' < "$manifest_path" \
        | sed -nE 's/^[[:space:]]*[{]?[[:space:]]*"'"$key"'"[[:space:]]*:[[:space:]]*"([^"]*)"[[:space:]]*[}]?[[:space:]]*$/\1/p'
}

manifest_number() {
    local key="$1"

    tr ',' '\n' < "$manifest_path" \
        | sed -nE 's/^[[:space:]]*[{]?[[:space:]]*"'"$key"'"[[:space:]]*:[[:space:]]*([0-9]+)[[:space:]]*[}]?[[:space:]]*$/\1/p'
}

has_manifest_key() {
    local key="$1"

    grep -Eq '^[[:space:]]*"'"$key"'"[[:space:]]*:' "$manifest_path"
}

is_recognized_legacy_manifest() {
    local created_at
    local manifest_key_count

    if has_manifest_key format_version || has_manifest_key postgres_sha256 || has_manifest_key artifacts_sha256; then
        return 1
    fi

    created_at="$(manifest_string created_at)"
    manifest_key_count="$(grep -Ec '^[[:space:]]*"[^"]+"[[:space:]]*:' "$manifest_path" || true)"

    [[ "$manifest_key_count" == "9" ]] \
        && head -n 1 "$manifest_path" | grep -Eq '^[[:space:]]*{[[:space:]]*$' \
        && [[ "$created_at" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] \
        && [[ "$(manifest_string ordering)" == "postgres_dump_first_artifacts_snapshot_second" ]] \
        && [[ "$(manifest_string postgres_dump)" == "postgres.dump" ]] \
        && [[ "$(manifest_string artifacts_archive)" == "artifacts.tar.gz" ]] \
        && [[ "$(manifest_number page_versions_count)" =~ ^[0-9]+$ ]] \
        && [[ "$(manifest_number artifact_file_count)" =~ ^[0-9]+$ ]] \
        && grep -Eq '^[[:space:]]*"postgres_version"[[:space:]]*:[[:space:]]*"[^"]+"[[:space:]]*,?[[:space:]]*$' "$manifest_path" \
        && grep -Eq '^[[:space:]]*"tar_version"[[:space:]]*:[[:space:]]*"[^"]+"[[:space:]]*,?[[:space:]]*$' "$manifest_path" \
        && grep -Eq '^[[:space:]]*"compose_version"[[:space:]]*:[[:space:]]*"[^"]+"[[:space:]]*,?[[:space:]]*$' "$manifest_path" \
        && tail -n 1 "$manifest_path" | grep -Eq '^[[:space:]]*}[[:space:]]*$'
}

upgrade_legacy_manifest_file() {
    local artifacts_sha256
    local postgres_sha256
    local temporary_manifest

    if [[ -L "$manifest_path" ]] || ! is_recognized_legacy_manifest; then
        printf 'Refusing upgrade because this is not a recognized legacy ArtifactFlow backup manifest: %s\n' "$manifest_path" >&2
        exit 1
    fi

    printf 'WARNING: Upgrading legacy unhashed backup manifest after explicit operator request: %s\n' "$manifest_path" >&2
    printf 'The legacy format cannot prove historical payload pairing; verify this backup provenance before continuing.\n' >&2

    postgres_sha256="$(sha256_file "$postgres_dump")"
    artifacts_sha256="$(sha256_file "$artifacts_archive")"
    temporary_manifest="$(mktemp "${backup_dir%/}/.manifest.json.upgrade.XXXXXX")"
    chmod 600 "$temporary_manifest"

    {
        sed '$d' "$manifest_path" | sed '$s/[[:space:]]*$/,/'
        printf '  "format_version": 1,\n'
        printf '  "postgres_sha256": "%s",\n' "$postgres_sha256"
        printf '  "artifacts_sha256": "%s"\n' "$artifacts_sha256"
        printf '}\n'
    } > "$temporary_manifest"

    mv -f -- "$temporary_manifest" "$manifest_path"
    chmod 600 "$manifest_path"
    printf 'Legacy backup manifest upgraded with SHA-256 payload hashes: %s\n' "$manifest_path" >&2
}

require_quiescent_application_roles() {
    local active_services=""
    local lifecycle_state
    local service
    local state_services
    local unsafe_service
    local -a active_roles=()

    for lifecycle_state in running paused restarting; do
        if ! state_services="$($compose_cmd ps --services --status "$lifecycle_state")"; then
            printf 'Unable to verify that application roles are quiescent in Compose state %s.\n' "$lifecycle_state" >&2
            exit 1
        fi

        active_services+="${active_services:+$'\n'}${state_services}"
    done

    for service in "$app_service" artifact-host worker scheduler; do
        while IFS= read -r unsafe_service; do
            if [[ "$unsafe_service" == "$service" ]]; then
                active_roles+=("$service")
                break
            fi
        done <<< "$active_services"
    done

    if [[ "${#active_roles[@]}" -gt 0 ]]; then
        printf 'Refusing restore while application roles are running, paused, or restarting: %s. Stop app, artifact-host, worker, and scheduler first.\n' "${active_roles[*]}" >&2
        exit 1
    fi
}

validate_artifacts_archive() {
    local archive="$1"
    local unsafe_path
    local unsafe_link

    unsafe_path="$(tar -tzf "$archive" | awk '$0 ~ /^\/|(^|\/)\.\.($|\/)/ && found == 0 { print; found=1 }')" || {
        printf 'Unable to inspect artifacts archive paths: %s\n' "$archive" >&2
        exit 1
    }

    if [[ -n "$unsafe_path" ]]; then
        printf 'Refusing artifacts archive with unsafe member path: %s\n' "$unsafe_path" >&2
        exit 1
    fi

    unsafe_link="$(tar -tvzf "$archive" | awk '(substr($0, 1, 1) == "l" || substr($0, 1, 1) == "h") && found == 0 { print; found=1 }')" || {
        printf 'Unable to inspect artifacts archive member types: %s\n' "$archive" >&2
        exit 1
    }

    if [[ -n "$unsafe_link" ]]; then
        printf 'Refusing artifacts archive with symlink or hardlink member: %s\n' "$unsafe_link" >&2
        exit 1
    fi
}

if [[ "$dry_run" -eq 1 ]]; then
    printf 'Would restore PostgreSQL with pg_restore --clean --if-exists from: %s\n' "$postgres_dump"
    printf 'Would extract private artifacts from: %s\n' "$artifacts_archive"
    printf 'Would run post-restore verification with: make backup-verify\n'
    exit 0
fi

if [[ ! -f "$postgres_dump" ]]; then
    printf 'PostgreSQL dump not found: %s\n' "$postgres_dump" >&2
    exit 1
fi

if [[ ! -f "$artifacts_archive" ]]; then
    printf 'Artifacts archive not found: %s\n' "$artifacts_archive" >&2
    exit 1
fi

if [[ ! -f "$manifest_path" ]]; then
    printf 'Backup manifest not found: %s\n' "$manifest_path" >&2
    exit 1
fi

format_version="$(tr ',' '\n' < "$manifest_path" | sed -nE 's/^[[:space:]]*[{]?[[:space:]]*"format_version"[[:space:]]*:[[:space:]]*([0-9]+)[[:space:]]*[}]?[[:space:]]*$/\1/p')"

if [[ -z "$format_version" ]] && [[ "$upgrade_legacy_manifest" -eq 1 ]]; then
    upgrade_legacy_manifest_file
    format_version="$(tr ',' '\n' < "$manifest_path" | sed -nE 's/^[[:space:]]*[{]?[[:space:]]*"format_version"[[:space:]]*:[[:space:]]*([0-9]+)[[:space:]]*[}]?[[:space:]]*$/\1/p')"
fi

expected_postgres_sha256="$(manifest_hash postgres_sha256)"
expected_artifacts_sha256="$(manifest_hash artifacts_sha256)"

if [[ "$format_version" != "1" ]] || [[ -z "$expected_postgres_sha256" ]] || [[ -z "$expected_artifacts_sha256" ]]; then
    printf 'Backup manifest is missing a supported format version or SHA-256 pairing metadata: %s\n' "$manifest_path" >&2
    exit 1
fi

if [[ "$(sha256_file "$postgres_dump")" != "$expected_postgres_sha256" ]]; then
    printf 'PostgreSQL dump hash does not match backup manifest: %s\n' "$postgres_dump" >&2
    exit 1
fi

if [[ "$(sha256_file "$artifacts_archive")" != "$expected_artifacts_sha256" ]]; then
    printf 'Artifacts archive hash does not match backup manifest: %s\n' "$artifacts_archive" >&2
    exit 1
fi

compose_cmd="${COMPOSE:-docker compose}"
app_service="${APP_SERVICE:-app}"

require_quiescent_application_roles
validate_artifacts_archive "$artifacts_archive"

db_table_count="$($compose_cmd exec -T db sh -lc 'PGPASSWORD="${APP_DB_PASS:?APP_DB_PASS must be set}" psql -X -At -U "${APP_DB_USER:?APP_DB_USER must be set}" -d "${POSTGRES_DB:?POSTGRES_DB must be set}" -c "select count(*) from pg_tables where schemaname = '\''public'\'';"' | tr -d '[:space:]')"
artifact_file_count="$($compose_cmd run --rm --no-deps --entrypoint sh "$app_service" -lc 'artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"; if [ -d "$artifact_root" ]; then find "$artifact_root" -type f | wc -l | tr -d " "; else printf 0; fi' | tr -d '[:space:]')"

if { [[ "${db_table_count:-0}" != "0" ]] || [[ "${artifact_file_count:-0}" != "0" ]]; } && [[ "$force" -ne 1 ]]; then
    printf 'Refusing to restore over non-empty state without --force.\n' >&2
    printf 'Current public table count: %s; current artifact file count: %s.\n' "${db_table_count:-0}" "${artifact_file_count:-0}" >&2
    exit 1
fi

if [[ "$force" -eq 1 ]]; then
    printf 'This will overwrite database objects and artifact files from %s.\n' "$backup_dir" >&2
    printf 'Type RESTORE to continue: ' >&2
    read -r confirmation
    if [[ "$confirmation" != "RESTORE" ]]; then
        printf 'Restore cancelled.\n' >&2
        exit 1
    fi
fi

printf 'Restoring PostgreSQL from: %s\n' "$postgres_dump"
$compose_cmd exec -T db sh -lc 'PGPASSWORD="${APP_DB_PASS:?APP_DB_PASS must be set}" pg_restore --clean --if-exists -U "${APP_DB_USER:?APP_DB_USER must be set}" -d "${POSTGRES_DB:?POSTGRES_DB must be set}"' < "$postgres_dump"

printf 'Restoring private artifacts from: %s\n' "$artifacts_archive"
$compose_cmd run --rm --no-deps --entrypoint sh "$app_service" -lc 'artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"; mkdir -p "$artifact_root"; if find "$artifact_root" -mindepth 1 -type l -print -quit | grep -q .; then printf "Refusing to extract artifacts into a root containing symlinks.\n" >&2; exit 1; fi; tar_flags=""; if tar --help 2>&1 | grep -q -- "--no-same-owner"; then tar_flags="$tar_flags --no-same-owner"; fi; if tar --help 2>&1 | grep -q -- "--no-same-permissions"; then tar_flags="$tar_flags --no-same-permissions"; fi; tar -C "$artifact_root" $tar_flags -xzf -' < "$artifacts_archive"

printf 'Restore complete. Run post-restore verification with: make backup-verify\n'
