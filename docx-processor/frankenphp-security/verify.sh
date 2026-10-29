#!/bin/sh
set -eu

test "$#" -eq 1
binary=$1
notices=/opt/artifactflow-frankenphp-notices
go version -m "$binary" > "$notices/build-info.txt"
grep -E '^[[:space:]]+dep[[:space:]]+github\.com/go-jose/go-jose/v4[[:space:]]+v4\.1\.6[[:space:]]' "$notices/build-info.txt"
if grep -E 'github\.com/go-jose/go-jose/v3([[:space:]]|$)' "$notices/build-info.txt"; then
    echo 'Refusing a binary that still embeds go-jose/v3.' >&2
    exit 1
fi
grep -F '/opt/artifactflow-frankenphp-sources/crypto' "$notices/build-info.txt"
grep -F '/opt/artifactflow-frankenphp-sources/certificates' "$notices/build-info.txt"
grep -F '/opt/artifactflow-frankenphp-sources/cli-utils' "$notices/build-info.txt"
echo 'FrankenPHP embeds go-jose/v4.1.6 and records all three source migrations.'
sh /opt/artifactflow-frankenphp-security/smoke-tls.sh "$binary"
