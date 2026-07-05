#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: scripts/restore.sh [--dry-run] [--force] <backup-directory>

Restores postgres.dump and artifacts.tar.gz from a backup directory.
Restoring over non-empty state requires --force and typing RESTORE.
USAGE
}

dry_run=0
force=0
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

validate_artifacts_archive "$artifacts_archive"

compose_cmd="${COMPOSE:-docker compose}"
app_service="${APP_SERVICE:-app}"

db_table_count="$($compose_cmd exec -T db sh -lc 'PGPASSWORD="${APP_DB_PASS:?APP_DB_PASS must be set}" psql -X -At -U "${APP_DB_USER:?APP_DB_USER must be set}" -d "${POSTGRES_DB:?POSTGRES_DB must be set}" -c "select count(*) from pg_tables where schemaname = '\''public'\'';"' | tr -d '[:space:]')"
artifact_file_count="$($compose_cmd exec -T "$app_service" sh -lc 'artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"; if [ -d "$artifact_root" ]; then find "$artifact_root" -type f | wc -l | tr -d " "; else printf 0; fi' | tr -d '[:space:]')"

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
$compose_cmd exec -T "$app_service" sh -lc 'artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"; mkdir -p "$artifact_root"; if find "$artifact_root" -mindepth 1 -type l -print -quit | grep -q .; then printf "Refusing to extract artifacts into a root containing symlinks.\n" >&2; exit 1; fi; tar_flags=""; if tar --help 2>&1 | grep -q -- "--no-same-owner"; then tar_flags="$tar_flags --no-same-owner"; fi; if tar --help 2>&1 | grep -q -- "--no-same-permissions"; then tar_flags="$tar_flags --no-same-permissions"; fi; tar -C "$artifact_root" $tar_flags -xzf -' < "$artifacts_archive"

printf 'Restore complete. Run post-restore verification with: make backup-verify\n'
