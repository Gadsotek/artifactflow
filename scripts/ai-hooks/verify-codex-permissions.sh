#!/bin/sh
set -eu

# Synthetic native-profile test. Never opens real secrets or touches source mtimes.
repository_root=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
exec python3 "$repository_root/scripts/agent-containment/run_codex.py" --repo "$repository_root" "$@"
