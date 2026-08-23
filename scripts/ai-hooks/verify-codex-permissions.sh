#!/bin/sh
set -eu

repository_root=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
codex_global_config=${CODEX_CONFIG_PATH:-"${HOME}/.codex/config.toml"}

command -v codex >/dev/null 2>&1 || {
    echo 'Codex CLI is required for the permission-profile canary.' >&2
    exit 2
}

if [ ! -f "$codex_global_config" ]; then
    echo "Codex global config was not found at $codex_global_config." >&2
    exit 2
fi

if rg -q '^[[:space:]]*sandbox_mode[[:space:]]*=' "$codex_global_config"; then
    echo 'Legacy sandbox_mode is still configured and disables permission profiles.' >&2
    exit 2
fi

if rg -q '^\[sandbox_workspace_write\]' "$codex_global_config"; then
    echo 'Legacy sandbox_workspace_write is still configured and disables permission profiles.' >&2
    exit 2
fi

if ! rg -q '^[[:space:]]*approval_policy[[:space:]]*=[[:space:]]*"on-request"' "$codex_global_config"; then
    echo 'Codex approval_policy must remain on-request for protected-path escalation.' >&2
    exit 2
fi

if ! rg -q '^[[:space:]]*approvals_reviewer[[:space:]]*=[[:space:]]*"user"' "$codex_global_config"; then
    echo 'Codex approvals_reviewer must be user so protected-path prompts reach a human.' >&2
    exit 2
fi

if codex sandbox -P artifactflow-edit -C "$repository_root" /usr/bin/touch "$repository_root/Makefile" >/dev/null 2>&1; then
    echo 'Permission canary failed: Codex could write the protected Makefile.' >&2
    exit 2
fi

if ! codex sandbox -P artifactflow-edit -C "$repository_root" /usr/bin/touch "$repository_root/composer.json" >/dev/null 2>&1; then
    echo 'Permission canary failed: ordinary workspace files are not writable.' >&2
    exit 2
fi

if [ -f "$repository_root/.env" ] \
    && codex sandbox -P artifactflow-edit -C "$repository_root" /usr/bin/head -c 0 "$repository_root/.env" >/dev/null 2>&1; then
    echo 'Permission canary failed: Codex could open the denied .env file.' >&2
    exit 2
fi

echo 'Codex permission profile passed: control files are read-only and .env is denied.'
