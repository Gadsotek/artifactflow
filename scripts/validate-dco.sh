#!/bin/sh

set -eu

DCO_PATTERN='^Signed-off-by: [^[:space:]<]([^<]*[^[:space:]<])? <[^[:space:]@<>][^[:space:]@<>]*@[^[:space:]@<>][^[:space:]@<>]*\.[^[:space:]@<>][^[:space:]@<>]*>$'

if [ "${1:-}" = "--message-stdin" ]; then
    if git interpret-trailers --parse | grep -qE "$DCO_PATTERN"; then
        exit 0
    fi

    echo 'Commit message is missing a valid Signed-off-by trailer.' >&2
    exit 1
fi

if [ "$#" -ne 2 ]; then
    echo 'Usage: validate-dco.sh <base-sha> <head-sha>' >&2
    exit 2
fi

base_sha=$1
head_sha=$2
range="${base_sha}..${head_sha}"
missing=0

for sha in $(git rev-list --no-merges "$range"); do
    if git log -1 --format=%B "$sha" | git interpret-trailers --parse | grep -qE "$DCO_PATTERN"; then
        continue
    fi

    short=$(git log -1 --format='%h %s' "$sha")
    echo "::error::Commit missing valid Signed-off-by trailer: $short" >&2
    echo "  Add a sign-off with 'git commit --amend -s' or rebase with --signoff." >&2
    missing=$((missing + 1))
done

if [ "$missing" -gt 0 ]; then
    echo "::error::$missing commit(s) missing DCO sign-off. See CONTRIBUTING.md." >&2
    exit 1
fi

echo "All commits in $range carry a valid Signed-off-by trailer."
