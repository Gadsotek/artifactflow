# Verification and release images

[Operations](../OPERATIONS.md)

## Quality Gates

Local fast gate:

```sh
make quality
```

Full local pre-push gate:

```sh
make quality-full
```

The production-image phase uses a fresh `artifactflow-quality-<random>` Buildx
builder and six temporary image tags. It runs the existing production builds,
processor runtime checks, and Trivy scans against those same tags, then removes
the tags and the temporary builder's cache. It does not select a different
default builder, prune Docker globally, or delete existing container resources.
Shared development/E2E images, database volumes, and dependency caches remain.
Each subsequent full production build starts with a fresh cache, so it may take
longer. Standalone `make build-prod` and `make scan-image` retain their existing
image names and support the existing build/cache overrides.

Cleanup runs after success, command failure, and handled INT/TERM/HUP signals.
It reports failures and fails an otherwise successful gate if cleanup fails;
it preserves the original failure status when a build or scan already failed.
Power loss, SIGKILL, or an unavailable Docker engine can prevent cleanup. In
that case, use the exact temporary builder/image names printed by the run to
inspect leftovers before removing them; do not use a global prune command.
The command-level lifecycle tests run in CI and with `make quality`, and can also run
without Docker via `node --test scripts/quality-images.test.mjs`.

`make e2e` creates a temporary database, starts dedicated app/artifact services,
and drops the database on exit. Defaults are `http://localhost:18180` and
`http://127.0.0.1:18181`; override occupied ports with `E2E_APP_PORT` and
`E2E_ARTIFACT_HOST_PORT`. Different hosts keep app cookies off the artifact origin.
The committed `docker/e2e.env` interpolation guard prevents personal environment
values from entering the test stack. Setup commands target only the isolated app.

Every Playwright test runs on Chromium. Tests marked `@artifact-security` also run on Firefox and
WebKit; add that title tag whenever a regression depends on CSP, iframe sandboxing, origin/cookie
isolation, nested browsing contexts, browser networking behavior, or Mermaid sanitization.

Run the deterministic draft-capability mutation corpus independently when changing the token
format, signing context, claim validation, expiry, or content binding:

```sh
make fuzz-capabilities
```

This first proves that a pristine issued token is accepted, then mutates every payload character
and signature nibble, exercises malformed-token and correctly signed invalid-claim corpora, and
checks exact-byte content binding. It is deterministic and runs as part of the ordinary Pest suite;
the focused command is for local iteration. Signature comparison uses PHP's `hash_equals`, while
cryptographic review or a dedicated statistical timing assessment remains separate work.

The Playwright security corpus also includes a bounded differential fuzzer for the hand-maintained
artifact response rewriter. It generates tokenizer and tree-builder state combinations, invokes the
exact PHP rewriter without injecting the runtime JavaScript guard, and asks Chromium, Firefox, and
WebKit to parse both the raw and rewritten bytes. Rewritten documents must always report
`window.frames.length === 0`; parsing without the runtime guard ensures that later DOM cleanup
cannot hide a response-time miss. CI uses the first 32 bits of `GITHUB_SHA` as a reproducible seed,
while local runs use a stable fallback. Failures print the seed, case index, payload, and command
needed to reproduce them. Run the default 128-case corpus or expand it up to the bounded 512-case
limit with:

```sh
E2E_GREP='artifact parser differential fuzz corpus' make e2e
ARTIFACT_PARSER_FUZZ_SEED=123 ARTIFACT_PARSER_FUZZ_CASES=512 \
  E2E_GREP='artifact parser differential fuzz corpus' make e2e
```

CI runs:

- Gitleaks secret scan.
- Docker Compose config validation.
- ECS PSR-12 style gate.
- Larastan at max level (empty baseline).
- Semgrep with the ArtifactFlow rules plus the general PHP and security-audit rulesets.
- Composer audit and npm audit at moderate-or-higher severity.
- Pest test suite, including the deterministic draft-capability verifier mutation corpus.
- 100% type-coverage enforcement.
- PCOV line-coverage enforcement against the committed `COVERAGE_MIN` floor.
- Vite asset build.
- Full Playwright E2E suite on Chromium, plus the tagged artifact security corpus; including the
  seeded artifact-parser differential fuzzer; on Firefox and WebKit.
- Production Caddy/FrankenPHP image plus the image, PDF, XLSX, and DOCX
  parser/processor builds and runtime contracts, including the DOCX-to-PDFBox
  searchable-preview chain.
- Trivy vulnerability, secret, and misconfiguration scans of every built image.
- Trivy filesystem scan combining repository secret and misconfiguration checks.

The repository scan excludes dependencies, generated build/cache output, Git
metadata, and the separate local checkouts under `.codex-*` and
`.tmp/podman-documents`. Those checkouts need their own security checks. Other
files under `.tmp` remain in scope.

Nightly automation repeats dependency audits, production builds, and Trivy to
surface newly reported vulnerabilities. Protected branches must require both
`ci-required` (including DCO) and the separate `cla` check. The CLA workflow runs
on `pull_request_target` and cannot be folded into the aggregate.

The Turnstile browser test loads real Cloudflare widgets with published test
credentials on a separate test server, so `make e2e` needs outbound HTTPS to
`challenges.cloudflare.com`. Server action/hostname validation remains covered
by deterministic feature tests. Production rejects those test credentials.

[Manual Safari and iOS pass](browser-checks.md).

## Verifying Release Images

Every `v*` tag runs the `Release` workflow, which builds and scans the application, PDF processor, XLSX processor, and DOCX processor images; pushes them to separate GHCR repositories; and publishes a GitHub Release whose notes carry all four immutable image digests. Always deploy by digest, not by tag. The `:latest` tag is moved only for a final `vMAJOR.MINOR.PATCH` release; a pre-release tag (for example `v1.2.0-rc1`) publishes its exact version tags but never becomes `:latest`, so pulling `:latest` cannot land on an unfinished build.

Each published image carries two keyless-signed (Sigstore) attestations bound to its digest and pushed alongside it in the registry: SLSA build provenance and a CycloneDX SBOM. The release attaches `sbom.cdx.json`, `sbom.pdf-processor.cdx.json`, `sbom.xlsx-processor.cdx.json`, and `sbom.docx-processor.cdx.json`. Verify every deployed image before running it, using the digests from the release notes:

```sh
gh attestation verify \
  oci://ghcr.io/gadsotek/artifactflow@sha256:<digest> \
  --repo Gadsotek/artifactflow
gh attestation verify \
  oci://ghcr.io/gadsotek/artifactflow-pdf-processor@sha256:<digest> \
  --repo Gadsotek/artifactflow
gh attestation verify \
  oci://ghcr.io/gadsotek/artifactflow-xlsx-processor@sha256:<digest> \
  --repo Gadsotek/artifactflow
gh attestation verify \
  oci://ghcr.io/gadsotek/artifactflow-docx-processor@sha256:<digest> \
  --repo Gadsotek/artifactflow
```

A successful verification confirms the image was produced by this repository's release pipeline and was not tampered with after signing. If verification fails, do not deploy the image.
