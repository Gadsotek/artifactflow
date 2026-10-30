# FrankenPHP JOSE dependency migration

The app and DOCX images rebuild their pinned FrankenPHP 1.13.2 sources with
Go 1.27.3. This directory contains a temporary, build-only source migration for
the Smallstep dependencies that still import unpatched go-jose/v3.0.5.
CVE-2026-116587 / GHSA-mgcc-cww5-fp2q affects RSA JWK parsing; upstream lists
no fixed v3 release. The resulting executable must use the official
go-jose/v4.1.6 module and must contain no v3 packages or build-info dependency.
The Trivy gate remains unchanged.

## Inputs and modifications

`prepare.sh` verifies exact source archive SHA256 values before extraction and
patch hashes before applying them. It refuses a changed starting module version.
The patches have zero context so storing Go's tab-indented source diffs does
not introduce whitespace errors into the repository. `--unidiff-zero` is used
only after verifying the exact starting archives; patch application never
targets an unverified source revision.
Both Dockerfiles use the same files, housed here because the DOCX image has a
dedicated build context.

- `crypto.patch` migrates Smallstep crypto 0.91.0 using the source changes from
  [Smallstep crypto PR 1157](https://github.com/smallstep/crypto/pull/1157),
  reviewed at commit `00c45432f48558f92a6e7da6d885d94e90db4d7a`, and selects
  the patched go-jose 4.1.6 release. It preserves signed JSON JWS bytes and the
  supported parser algorithm lists. Additional compatibility changes retain
  X25519 signing through v4's opaque signer API and reject requests to embed
  an unsupported X25519 JWK. Randomized encryption tests verify authenticated
  plaintext and headers without replacing v4's secure random source.
- `certificates.patch` updates Smallstep certificates 0.30.2 JWT builder calls
  and four expected-audience fields. Each migrated production audience list is
  a literal singleton, preserving its existing match and rejection behavior.
  This does not assert compatibility for external callers of the Smallstep
  library's changed public API.
- `cli-utils.patch` updates the Smallstep cli-utils 0.12.2 token builder's
  serialization call to the v4 API.

The replacements remain visible as local source replacements in Go build
metadata. Upstream module versions are never relabelled. Original licenses,
input and patch hashes, this explanation, and compiled build information are
retained at `/usr/local/share/licenses/artifactflow-frankenphp` in both images.
These are modified Apache-2.0 Smallstep sources; the upstream licenses remain
authoritative.

The v4 dependency deliberately rejects malformed Ed25519 key pairs and
undersized HMAC keys that v3 accepted. Fixtures now distinguish those invalid
keys from valid signing and encryption compatibility controls.

## Verification and removal

Every image build runs the modified JOSE suite, token builder suite, ACME,
provisioner and stepcas suites using the application's selected dependency
versions, plus the upstream go-jose suite including its private randomness
failure tests. Dependency unit tests use pure Go; the actual FrankenPHP binary
retains upstream's normal CGO build. The added regression must first fail
against the original sources:
it checks early rejection of an oversized synthetic RSA modulus, with normal
RSA/EC/HMAC signatures, signed JSON byte preservation, singleton audiences,
issuer/time validation and X25519 signing as compatibility controls.

The build then checks the full production package closure and compiled binary
for v3 absence, requires v4.1.6, and retains the existing Go/x/net/x/crypto/gRPC/
kin-openapi version checks. A dormant v3 requirement may remain in upstream
module metadata; it must never become executable code. Normal production
runtime, artifact-header, browser and image-scan gates remain required. Each
compiled binary also runs a loopback internal-TLS probe with a generated CA,
hostname/CA verification and no trust-store installation.

Remove this migration when a supported pinned FrankenPHP/Caddy/Smallstep graph
provides the same fixes. Verify dependency metadata, behavior and scans before
removing its source and regression checks.

Sources: [advisory](https://github.com/go-jose/go-jose/security/advisories/GHSA-mgcc-cww5-fp2q),
[crypto 0.91.0](https://github.com/smallstep/crypto/tree/v0.91.0),
[certificates 0.30.2](https://github.com/smallstep/certificates/tree/v0.30.2),
[cli-utils 0.12.2](https://github.com/smallstep/cli-utils/tree/v0.12.2),
[go-jose 4.1.6](https://github.com/go-jose/go-jose/tree/v4.1.6).
