#!/bin/sh

set -eu

DCO_PATTERN='^Signed-off-by: [^[:space:]<]([^<]*[^[:space:]<])? <[^[:space:]@<>][^[:space:]@<>]*@[^[:space:]@<>][^[:space:]@<>]*\.[^[:space:]@<>][^[:space:]@<>]*>$'

parse_trailers() {
    git --git-dir="${TMPDIR:-/tmp}" -c core.bare=true interpret-trailers --parse
}

has_valid_dco() {
    message=$1

    if printf '%s\n' "$message" | parse_trailers | grep -qE "$DCO_PATTERN"; then
        return 0
    fi

    # Dependabot puts its trailer after a `---` metadata block. Git treats that
    # marker as a patch divider and omits the otherwise valid final trailer.
    # Accept only an isolated final non-empty line so body text cannot qualify.
    printf '%s\n' "$message" | awk '
        { lines[NR] = $0 }
        END {
            last = NR
            while (last > 0 && lines[last] ~ /^[[:space:]]*$/) {
                last--
            }
            if (last > 1 && lines[last - 1] ~ /^[[:space:]]*$/) {
                print lines[last]
            }
        }
    ' | grep -qE "$DCO_PATTERN"
}

if [ "${1:-}" = "--message-stdin" ]; then
    message=$(cat)

    if has_valid_dco "$message"; then
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
    message=$(git log -1 --format=%B "$sha")

    if has_valid_dco "$message"; then
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
