#!/bin/sh
set -eu
umask 077

test "$#" -eq 1
mode=$1
case "$mode" in red|green) ;; *) echo 'Expected red or green.' >&2; exit 2 ;; esac
kit=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
fixture=$(mktemp -d "${TMPDIR:-/tmp}/artifactflow-jose-regression.XXXXXX")
echo "Retained dependency-only regression fixture: $fixture"

cd "$kit"
shasum -a 256 -c patches.sha256
curl -fsSL --retry 3 https://codeload.github.com/smallstep/crypto/tar.gz/refs/tags/v0.91.0 -o "$fixture/crypto.tar.gz"
cd "$fixture"
printf '%s\n' '19ff1e93afe506884f799c512b3ce8b4e0dc84a70fb6f60e77c53d01596f9ce1  crypto.tar.gz' | shasum -a 256 -c -
mkdir source
tar -xzf crypto.tar.gz --strip-components=1 -C source
if [ "$mode" = green ]; then
    git -C source apply --unidiff-zero --check "$kit/crypto.patch"
    git -C source apply --unidiff-zero "$kit/crypto.patch"
fi
cp "$kit/artifactflow_migration_test.go" source/jose/

# This standalone Go container sees only its disposable fixture. It has no
# application source, app secrets, Docker socket or database connection.
if [ "$mode" = red ]; then
    docker run --rm --cap-drop=ALL --security-opt=no-new-privileges \
        --pids-limit=256 --memory=3g --cpus=2 \
        --mount "type=bind,source=$fixture/source,target=/work" \
        --workdir /work --env GOENV=off --env GOTOOLCHAIN=local \
        --env GOMODCACHE=/work/.module-cache --env GOCACHE=/work/.build-cache \
        golang:1.27.4-alpine3.24@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 \
        go test -mod=mod -count=1 -timeout=2m -run '^TestArtifactFlowRejectsOversizedRSAJWKBeforeKeyOperations$' ./jose
else
    docker run --rm --cap-drop=ALL --security-opt=no-new-privileges \
        --pids-limit=256 --memory=3g --cpus=2 \
        --mount "type=bind,source=$fixture/source,target=/work" \
        --workdir /work --env GOENV=off --env GOTOOLCHAIN=local \
        --env GOMODCACHE=/work/.module-cache --env GOCACHE=/work/.build-cache \
        golang:1.27.4-alpine3.24@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 \
        go test -mod=mod -count=1 -timeout=10m ./jose/...
fi
