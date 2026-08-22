#!/bin/sh
# Shell fallback for scripts/ensure-artifact-signing-key.php on hosts without PHP.
# Keep the behavior in sync with the PHP script.
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
env_path="${1:-$script_dir/../.env}"

. "$script_dir/local-boundary-secret.sh"

ensure_local_boundary_secret "$env_path" ARTIFACT_URL_SIGNING_KEY APP_KEY
sh "$script_dir/ensure-image-parser-shared-secret.sh" "$env_path"
sh "$script_dir/ensure-pdf-processor-shared-secret.sh" "$env_path"
