#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    cat <<'USAGE'
Usage: scripts/backup.sh [--dry-run]

Creates backups/<timestamp>/postgres.dump, artifacts.tar.gz, and manifest.json.
The PostgreSQL dump is created before the artifacts snapshot.
USAGE
}

dry_run=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            dry_run=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown backup option: %s\n' "$1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

compose_cmd="${COMPOSE:-docker compose}"
app_service="${APP_SERVICE:-app}"
backup_root="${BACKUP_DIR:-backups}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
target_dir="${backup_root%/}/${timestamp}"
postgres_dump="${target_dir}/postgres.dump"
artifacts_archive="${target_dir}/artifacts.tar.gz"
manifest_path="${target_dir}/manifest.json"

if [[ "$dry_run" -eq 1 ]]; then
    printf 'Would create backup directory: %s\n' "$target_dir"
    printf 'Would run PostgreSQL pg_dump first: %s\n' "$postgres_dump"
    printf 'Would snapshot private artifacts second: %s\n' "$artifacts_archive"
    printf 'Would write manifest without secrets: %s\n' "$manifest_path"
    exit 0
fi

mkdir -p "$target_dir"
chmod 700 "$target_dir"

printf 'Creating PostgreSQL dump first: %s\n' "$postgres_dump"
$compose_cmd exec -T db sh -lc 'PGPASSWORD="${APP_DB_PASS:?APP_DB_PASS must be set}" pg_dump -U "${APP_DB_USER:?APP_DB_USER must be set}" -d "${POSTGRES_DB:?POSTGRES_DB must be set}" -Fc' > "$postgres_dump"
chmod 600 "$postgres_dump"

printf 'Creating private artifacts snapshot second: %s\n' "$artifacts_archive"
$compose_cmd exec -T "$app_service" sh -lc 'artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"; mkdir -p "$artifact_root"; tar -C "$artifact_root" -czf - .' > "$artifacts_archive"
chmod 600 "$artifacts_archive"

page_versions_count="$($compose_cmd exec -T db sh -lc 'PGPASSWORD="${APP_DB_PASS:?APP_DB_PASS must be set}" psql -X -At -U "${APP_DB_USER:?APP_DB_USER must be set}" -d "${POSTGRES_DB:?POSTGRES_DB must be set}" -c "select count(*) from page_versions;"' | tr -d '[:space:]')"
artifact_file_count="$($compose_cmd exec -T "$app_service" sh -lc 'artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"; if [ -d "$artifact_root" ]; then find "$artifact_root" -type f | wc -l | tr -d " "; else printf 0; fi' | tr -d '[:space:]')"
postgres_version="$($compose_cmd exec -T db sh -lc 'pg_dump --version' | tr -d '\r')"
tar_version="$($compose_cmd exec -T "$app_service" sh -lc 'tar --version 2>/dev/null | head -n 1 || tar --help 2>&1 | head -n 1' | tr -d '\r')"
compose_version="$($compose_cmd version --short 2>/dev/null || $compose_cmd version 2>/dev/null | head -n 1)"

json_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{ print $1 }'

        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{ print $1 }'

        return
    fi

    printf 'Neither sha256sum nor shasum is available to hash backup files.\n' >&2
    exit 1
}

postgres_sha256="$(sha256_file "$postgres_dump")"
artifacts_sha256="$(sha256_file "$artifacts_archive")"

cat > "$manifest_path" <<JSON
{
  "format_version": 1,
  "created_at": "$(json_escape "$timestamp")",
  "ordering": "postgres_dump_first_artifacts_snapshot_second",
  "postgres_dump": "postgres.dump",
  "artifacts_archive": "artifacts.tar.gz",
  "postgres_sha256": "${postgres_sha256}",
  "artifacts_sha256": "${artifacts_sha256}",
  "page_versions_count": ${page_versions_count:-0},
  "artifact_file_count": ${artifact_file_count:-0},
  "postgres_version": "$(json_escape "$postgres_version")",
  "tar_version": "$(json_escape "$tar_version")",
  "compose_version": "$(json_escape "$compose_version")"
}
JSON
chmod 600 "$manifest_path"

printf 'Backup complete: %s\n' "$target_dir"
printf 'Run post-restore verification with: make backup-verify\n'
