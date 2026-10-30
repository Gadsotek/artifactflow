#!/bin/sh
set -eu

# Build-only source migration. Both images copy this same directory; keeping it
# in the DOCX build context avoids a second, independently drifting patch set.
kit=/opt/artifactflow-frankenphp-security
sources=/opt/artifactflow-frankenphp-sources
notices=/opt/artifactflow-frankenphp-notices

test "$(go list -m -f '{{.Version}}' go.step.sm/crypto)" = v0.91.0
test "$(go list -m -f '{{.Version}}' github.com/smallstep/certificates)" = v0.30.2
test "$(go list -m -f '{{.Version}}' github.com/smallstep/cli-utils)" = v0.12.2
test ! -e "$sources"
mkdir -p "$sources/crypto" "$sources/certificates" "$sources/cli-utils" "$notices"

cd "$sources"
wget -q -O crypto.tar.gz https://codeload.github.com/smallstep/crypto/tar.gz/refs/tags/v0.91.0
wget -q -O certificates.tar.gz https://codeload.github.com/smallstep/certificates/tar.gz/refs/tags/v0.30.2
wget -q -O cli-utils.tar.gz https://codeload.github.com/smallstep/cli-utils/tar.gz/refs/tags/v0.12.2
sha256sum -c "$kit/sources.sha256"
tar -xzf crypto.tar.gz --strip-components=1 -C crypto
tar -xzf certificates.tar.gz --strip-components=1 -C certificates
tar -xzf cli-utils.tar.gz --strip-components=1 -C cli-utils

cd "$kit"
sha256sum -c patches.sha256
git -C "$sources/crypto" apply --unidiff-zero --check "$kit/crypto.patch"
git -C "$sources/crypto" apply --unidiff-zero "$kit/crypto.patch"
git -C "$sources/certificates" apply --unidiff-zero --check "$kit/certificates.patch"
git -C "$sources/certificates" apply --unidiff-zero "$kit/certificates.patch"
git -C "$sources/cli-utils" apply --unidiff-zero --check "$kit/cli-utils.patch"
git -C "$sources/cli-utils" apply --unidiff-zero "$kit/cli-utils.patch"
cp "$kit/artifactflow_migration_test.go" "$sources/crypto/jose/"

# The local replacements deliberately remain visible in Go's build metadata.
# No upstream version is relabelled, and the original license texts accompany
# the compiled result in each production image.
cp "$sources/crypto/LICENSE" "$notices/smallstep-crypto-LICENSE"
cp "$sources/certificates/LICENSE" "$notices/smallstep-certificates-LICENSE"
cp "$sources/cli-utils/LICENSE" "$notices/smallstep-cli-utils-LICENSE"
cp "$kit/README.md" "$kit/sources.sha256" "$kit/patches.sha256" "$notices/"

cd /go/src/app/caddy
go mod edit -replace=go.step.sm/crypto=/opt/artifactflow-frankenphp-sources/crypto
go mod edit -replace=github.com/smallstep/certificates=/opt/artifactflow-frankenphp-sources/certificates
go mod edit -replace=github.com/smallstep/cli-utils=/opt/artifactflow-frankenphp-sources/cli-utils
go mod edit -require=github.com/go-jose/go-jose/v4@v4.1.6
go mod tidy
go mod download all

# Test with the actual application's selected dependency versions. The custom
# RSA regression is also run against the original sources before publication.
# These packages need no C bindings. The FrankenPHP builder supplies static
# PHP linker flags unsuitable for standalone Go test binaries; the production
# executable below is still built through upstream's normal CGO go.sh path.
CGO_ENABLED=0 go test -mod=readonly -count=1 -timeout=10m \
    github.com/go-jose/go-jose/v4 \
    go.step.sm/crypto/jose/... \
    github.com/smallstep/cli-utils/token/... \
    github.com/smallstep/certificates/acme \
    github.com/smallstep/certificates/acme/api \
    github.com/smallstep/certificates/authority/provisioner \
    github.com/smallstep/certificates/cas/stepcas

# A dormant v3 requirement can remain in an upstream go.mod. The executable's
# complete package closure must contain no v3 code.
go list -mod=readonly -deps -f '{{.ImportPath}}' ./frankenphp > "$sources/compiled-packages.txt"
if grep -E '^github\.com/go-jose/go-jose/v3(/|$)' "$sources/compiled-packages.txt"; then
    echo 'Refusing a FrankenPHP build that still compiles go-jose/v3.' >&2
    exit 1
fi

jose_module_cache=$(go env GOMODCACHE)
cp "$jose_module_cache/github.com/go-jose/go-jose/v4@v4.1.6/LICENSE" "$notices/go-jose-LICENSE"
