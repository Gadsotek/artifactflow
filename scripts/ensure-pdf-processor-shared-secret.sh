#!/bin/sh
# Shell fallback for scripts/ensure-pdf-processor-shared-secret.php on hosts without PHP.
# Keep the behavior in sync with the PHP script.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
env_path="${1:-$script_dir/../.env}"

. "$script_dir/local-boundary-secret.sh"

ensure_local_boundary_secret \
    "$env_path" \
    PDF_PROCESSOR_SHARED_SECRET \
    APP_KEY \
    ARTIFACT_URL_SIGNING_KEY \
    IMAGE_PARSER_SHARED_SECRET
