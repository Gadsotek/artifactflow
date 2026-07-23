#!/usr/bin/env bash
set -euo pipefail

# Private working docs live in the fully-ignored docs/internal/ bucket (no need
# to enumerate them here). The docs/internal/ ignore + the exact-docs check below
# together guarantee nothing under docs/ leaks beyond the published allowlist.

public_paths=(
    "docs/ARCHITECTURE.md"
    "docs/ARTIFACT-LIFECYCLE.md"
    "docs/OPERATIONS.md"
    "docs/architecture/README.md"
    "docs/architecture/overview.svg"
    "docs/architecture/workflows.svg"
    "THREAT-MODEL.md"
)

published_docs=(
    "docs/ARCHITECTURE.md"
    "docs/ARTIFACT-LIFECYCLE.md"
    "docs/OPERATIONS.md"
    "docs/architecture/README.md"
    "docs/architecture/overview.svg"
    "docs/architecture/workflows.svg"
)

if ! git check-ignore --no-index -q -- docs/internal; then
    printf 'publish-guard: docs/internal/ (private working bucket) must be git-ignored.\n' >&2
    exit 1
fi

if [[ -n "$(git ls-files -- docs/internal/)" ]]; then
    printf 'publish-guard: no file under docs/internal/ may be tracked.\n' >&2
    exit 1
fi

for path in "${public_paths[@]}"; do
    if git check-ignore --no-index -q -- "$path"; then
        printf 'publish-guard: expected public path to be visible to git: %s\n' "$path" >&2
        exit 1
    fi
done

expected_docs="$(printf '%s\n' "${published_docs[@]}" | sort)"
actual_docs="$(git ls-files --cached --others --exclude-standard -- docs | sort)"

if [[ "$actual_docs" != "$expected_docs" ]]; then
    printf 'publish-guard: unexpected public docs set.\nExpected:\n%s\nActual:\n%s\n' "$expected_docs" "$actual_docs" >&2
    exit 1
fi

readme_links=(
    "docs/architecture/README.md"
    "docs/architecture/overview.svg"
    "docs/architecture/workflows.svg"
    "docs/ARCHITECTURE.md"
    "docs/ARTIFACT-LIFECYCLE.md"
    "docs/OPERATIONS.md"
    "THREAT-MODEL.md"
)

architecture_links=(
    "docs/architecture/overview.svg"
    "docs/architecture/workflows.svg"
)

for path in "${readme_links[@]}"; do
    if [[ ! -e "$path" ]]; then
        printf 'publish-guard: README target is missing: %s\n' "$path" >&2
        exit 1
    fi

    if ! grep -Fq "($path)" README.md; then
        printf 'publish-guard: README does not link to: %s\n' "$path" >&2
        exit 1
    fi
done

for path in "${architecture_links[@]}"; do
    if [[ ! -e "$path" ]]; then
        printf 'publish-guard: architecture README target is missing: %s\n' "$path" >&2
        exit 1
    fi
done
