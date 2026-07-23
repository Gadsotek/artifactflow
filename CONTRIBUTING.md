# Contributing to ArtifactFlow

Thanks for your interest. ArtifactFlow is a self-hosted, versioned artifact vault for tools and
documents created with AI. It preserves authoritative artifacts and versions for people and agents,
with searchable content, governance, and safe previews where applicable. It is **security-first**
and **test-first**; contributions are held to that bar.

> **Security issues do NOT go here.** Never open a public issue or PR that describes a live
> vulnerability; follow [`SECURITY.md`](SECURITY.md) for private disclosure.

## Getting set up

The supported local development path is Docker-first (see the [README](README.md)):

```bash
make up                            # db, app, artifact-host, worker, scheduler (healthchecked)
make shell                         # open a shell in the app container
php artisan artifactflow:install   # migrations, first System Admin, optional demo content
```

**Host prerequisites.** Almost everything runs inside containers, but the gates expect a few
host tools: Docker with Compose v2, GNU `make`, `python3` (AI-hook self-test), `semgrep`
(`python3 -m pip install semgrep`), and Node.js + npm (Playwright e2e runner and asset builds).

**A note on the tracked `.claude/` and `.codex/` configs.** This project is developed with AI
agents in the loop (disclosed in the README). Those directories carry guardrail hooks that only
activate if you use those tools yourself — and, for Claude Code, only after you explicitly trust
the project folder. They block things like direct `php artisan test` runs that would hit your dev
database. You can ignore them entirely if you don't use these tools.

The app runs on **two origins** by design (the main app and an isolated artifact host); don't
collapse them. If you touch the artifact-rendering path, read [`THREAT-MODEL.md`](THREAT-MODEL.md)
first; its "contributor don'ts" are not optional.

## Required quality gates

**`make quality-full` is the authoritative aggregate for the Make-backed gates** — style, static
analysis, semgrep scanning, tests, type/line coverage, audits, asset + production builds, image
scan, and e2e. CI additionally requires the Rector dry run and Semgrep rule-fixture test listed in
[`AGENTS.md`](AGENTS.md) ("Required Gates"); run those separate commands too before opening a PR.

While iterating, the individual gates you'll reach for most:

```bash
make ecs          # coding standard (PHP CS Fixer / ECS)
make stan         # Larastan at max level (empty baseline; new errors block)
make test         # Pest/PHPUnit on an isolated Postgres DB (TEST_FILTER=Name to focus)
make e2e          # Playwright end-to-end (once, before the first run: make e2e-install)
make audit        # composer audit + npm audit + pinned-sanitizer guard
make build-prod   # production image builds
```

The complete browser suite runs on Chromium. Artifact-boundary regressions whose correctness
depends on browser enforcement or engine-specific behavior must include `@artifact-security` in
their Playwright test title; the harness automatically runs those tests on Firefox and WebKit too.
Use the tag for CSP, iframe sandboxing, artifact-origin routing or cookies, nested contexts,
browser networking APIs, and Mermaid sanitization—not for ordinary UI/layout coverage. See
[`tests/e2e/README.md`](tests/e2e/README.md). Playwright WebKit is not released Safari or iOS, so
the manual Safari/iOS release pass remains required.

Run the full suite via the Makefile only: in particular **use `make test`**, never
`php artisan test` / `pest` directly (the suite is wired to an isolated test database). The
project's engineering standards (layered architecture, strict types, command/handler structure, the
security rules) live in [`AGENTS.md`](AGENTS.md); please skim it.

## How we work

- **Tests first.** For any behavioral change, add or update a *failing* test first, then make it
  pass. Security-relevant behavior needs a regression test (e.g. "an outsider cannot view this",
  "removing a member revokes their access").
- **Strict typing.** `declare(strict_types=1)`, real return/param types, no `mixed`-dumping.
- **Authorization close to the action.** Authorization is enforced server-side in the application
  layer (`PageAccess` / the identity handlers). If you add an endpoint that touches a workspace-
  or page-scoped resource, it must check access and scope by tenant; don't rely on the UI.
- **Keep the security model intact.** Never add `allow-same-origin` to an artifact iframe, never
  weaken a CSP, never serve artifact bytes from the main origin. See `THREAT-MODEL.md`.
- **Small, focused PRs** with a clear description of what and why, and how you tested it.

## Licensing of contributions (please read)

ArtifactFlow is shared in good faith for anyone solving the same problem, and it stays open under
AGPL-3.0. The CLA below is **not** a copyright grab — you keep your copyright — it exists only to
keep the project sustainable by preserving the option of a separate commercial license.

ArtifactFlow is **AGPL-3.0-or-later** with a separate commercial license offered by the copyright
holder (see [`COMMERCIAL.md`](COMMERCIAL.md)). To keep that dual-licensing viable, contributions
require two things:

1. **DCO sign-off** — you certify the **Developer Certificate of Origin** (provenance of your
   work) by signing off every commit:

   ```bash
   git commit -s        # adds a "Signed-off-by: Your Name <you@example.com>" trailer
   ```

2. **CLA signature** — you sign the [Contributor License Agreement](CLA.md) once, by posting the
   comment `I have read the CLA Document and I hereby sign the CLA` on your first pull request
   (a bot will prompt you and record the signature). The CLA is a **license grant, not a
   copyright transfer**: you keep the copyright to your work and can license it to anyone else
   however you like. It grants the project owner the right to also offer your contribution under
   the commercial licensing path in `COMMERCIAL.md`, so the project can stay dual-licensed as a
   whole. Contributing on behalf of an employer? Contact the owner (see `COMMERCIAL.md`) for an
   entity-level agreement instead of signing individually.

Pull requests cannot be merged until both the DCO check and the CLA check are green: on
protected branches both are configured as **required status checks** (the DCO gate rides inside
the aggregate `ci-required` check; the `cla` check is required separately because it runs in a
`pull_request_target` workflow and cannot be folded into `ci-required`). Maintainers: the exact
branch-protection settings that make this enforcement real are listed in
[`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md).

**Per-file license headers:** first-party source deliberately carries **no** per-file SPDX or
copyright header; the whole work is governed by the root [`LICENSE`](LICENSE) (AGPL-3.0-or-later),
and the relicensing paper trail for the dual-license model rests on the CLA/DCO record above rather
than in-file notices. Do not add per-file headers unless the maintainer decides to standardize on
them repo-wide.

## Code of conduct

Be respectful and constructive. `CODE_OF_CONDUCT.md` documents the project community standard.
