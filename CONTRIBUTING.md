# Contributing

ArtifactFlow is a self-hosted workspace for AI-generated artifacts, backed by a
versioned artifact vault. Keep changes focused, readable, and covered by tests.
Report live vulnerabilities privately through [SECURITY.md](SECURITY.md).

## Start locally

Read [AGENTS.md](AGENTS.md), then follow the [README quickstart](README.md#try-it-locally).
Host tools for development/gates are Docker with Compose v2, GNU make, Python 3,
Node.js/npm, and Semgrep. App runtimes run inside Docker.

Use `make test TEST_FILTER=Name` for focused PHP checks and `make e2e` for browser
checks. Both create isolated temporary databases. Never run PHP tests directly
or point browser tests at development data. See [browser test setup](tests/e2e/README.md).

Tracked agent hooks activate only through their corresponding tools. Inspect
[the hook source and behavior](scripts/ai-hooks/README.md) before trusting them.

## Make the change

1. State the problem, scope, and relevant security contract.
2. Write a failing test for new behavior or a bug, then implement the fix.
3. Keep controllers/views thin, business rules in application services, PHP
   strictly typed, and authorization at the server write/read boundary.
4. Update the relevant docs and describe the actual checks in the PR.

Read the [threat model](THREAT-MODEL.md) before changing rendering or processing.
Preserve the separate app/artifact origins. Browser-dependent security tests
use `@artifact-security`, which runs them on Firefox/WebKit as well as Chromium.
Released Safari/iOS still needs the documented manual pass.

## Verify

```sh
make quality-full
make run-app-cmd APP_CMD='composer rector'
semgrep --test --config .semgrep/artifactflow.yml .semgrep/artifactflow.php --metrics=off
git diff --check
```

Run `make compose-config` when Docker/environment configuration changes.
The complete gate policy and documentation-only exception remain in
[AGENTS.md](AGENTS.md#required-gates). Do not weaken a test or gate to pass it.

`make quality-full` builds and scans its six production/processor images using
temporary tags and a separate Buildx builder. On success, failure, or a handled
interrupt, it removes those tags and that builder's cache. Existing containers,
images used by other workloads, data volumes, and shared builders are preserved.
Production builds start with a fresh cache on the next full run. To retain
images or use custom build-cache options, run `make build-prod` followed by
`make scan-image` explicitly. See the [verification guide](docs/operations/verification.md).

Office changes also need the processor contracts and real conversion chain
wired into `make build-prod`; useful focused commands are:

```sh
npm --prefix xlsx-processor-spike test
npm --prefix xlsx-viewer-spike test
make xlsx-processor-service-test
make docx-processor-test
```

DOCX verification must pass its exact LibreOffice output through the independent
PDFBox profile. Never move a native parser into Laravel to simplify testing.

## Keep documentation small

README introduces the product. Architecture explains the system. Operations
links focused runbooks. Security contracts retain required limits and residuals.
Link to an existing source instead of repeating it; avoid em dashes.

Wiki source lives in [docs/wiki](docs/wiki/Documentation.md), so wiki changes
can be reviewed in the same PR. After merge, a maintainer copies those six
Markdown files into a checkout of `artifactflow.wiki.git`, reviews the diff,
and publishes that separate repository. A main-repository PR does not itself
publish the wiki. Update the exact public-doc allowlist when adding a guide.

## Sign off and license

Contributions require a DCO sign-off on every commit:

```sh
git commit -s
```

Sign the one-time [CLA](CLA.md) when its bot prompts on your first PR, using:

```text
I have read the CLA Document and I hereby sign the CLA
```

The CLA grants licensing rights; you retain copyright. It supports the
[AGPL-3.0-or-later](LICENSE) and separate [commercial licensing](COMMERCIAL.md)
paths. Employer contributions may need an entity agreement with the owner.
Both `ci-required` (including DCO) and the separate `cla` check must pass before
merge. Maintainers configure protection as described in the
[release checklist](RELEASE-CHECKLIST.md).

First-party files use the root license without per-file headers. Preserve
third-party notices and the dependency license policy.

## Dependency updates

Dependabot covers Composer/npm and GitHub Actions. Renovate is restricted to
container pins. Bots prepare PRs; they do not auto-merge. `make audit` and
nightly automation check advisories, lock integrity, and the
[dependency license policy](security/dependency-license-policy.json).
New/missing license metadata needs a reviewed package-specific resolution,
not a broadened allowlist. Redistribution obligations still need human review.

### Laravel Rector release metadata

The Composer repository override is limited to the official
`driftingly/rector-laravel` repository. Its release workflow builds a distribution
commit and moves the version tag after the initial source tag. Packagist's
2.7.1 metadata still pointed to that initial source commit when this repair was
prepared, retaining the abandoned standalone
`symplify/rule-doc-generator-contracts` dependency. The final official 2.7.1
distribution resolves to `c472e216fe341cad9c6541ecab7b7b6d42d4166f` and uses the
contracts bundled by `rector/rector` instead. This does not remove every copy of
the contracts code, and Composer's abandoned-package audit remains enabled.

The scoped VCS entry reads official tags directly. `no-api` uses Git for
metadata to avoid exhausting GitHub's anonymous API quota while preserving
archive downloads. Version constraints remain stable SemVer constraints; the
lockfile records the reviewed immutable commit. Do not replace this with a
development branch, a root `replace`/`provide` declaration, an audit exclusion,
or hand-edited lock metadata.

Remove the override once Packagist indexes the final distribution for the
selected supported release. Regenerate the lock with Composer, verify its
source and distribution references against the official final tag, and run
`make audit`, the Rector dry run, and all normal commit gates. In particular,
the standalone abandoned contracts package must not reappear. See the
[upstream release workflow](https://github.com/driftingly/rector-laravel/blob/0a333a9c2394d18de71f63f5a3ecbd1777c9622a/.github/workflows/downgraded_release.yaml)
and [Composer VCS documentation](https://getcomposer.org/doc/05-repositories.md#vcs).

Follow the [Code of Conduct](CODE_OF_CONDUCT.md).
