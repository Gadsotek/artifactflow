# Alpha Release Checklist

Use this before enabling a live alpha environment. This checklist covers operator-dependent release gates that code and local tests cannot prove.

## Alpha Framing

This alpha validates the secure vault, sandboxed artifact preview, organization, versioning, search, workspace sharing, account security, and MCP loop for internal teams. MCP is in scope only through issued tokens (read-only or read-write, optionally bound to selected workspaces), normal page handlers, scanners, optimistic concurrency, and audit/domain-event traceability. There is no per-page AI-visibility approval gate: token scope plus ordinary page access is the boundary, as described in `THREAT-MODEL.md`.

Set tester expectations explicitly:

- Workspace invitations require the invitee to sign in with the invited email address.
- Version restore exists, but version diffing is not built yet.
- Account recovery supports self-service reset links and console break-glass paths; operators still need secure mail delivery and 2FA recovery custody.

## Before Inviting Users

- Confirm the authenticated app origin and artifact origin both resolve over HTTPS in the target environment.
- Confirm the two origins are distinct and match `APP_URL`, `ARTIFACT_URL`, `ARTIFACT_FRAME_ANCESTORS`, and `REVERB_ALLOWED_ORIGINS`.
- Set `TRUSTED_PROXIES` to the actual TLS-terminating edge or reverse-proxy addresses. Do not trust forwarded headers from arbitrary clients.
- Set `DB_SSLMODE=verify-full` for PostgreSQL and set `DB_SSLROOTCERT` to the mounted database CA or trusted CA bundle.
- Set `SESSION_SECURE_COOKIE=true` in production.
- Set `SESSION_ENCRYPT=true` in production.
- Set `SESSION_HTTP_ONLY=true` in production.
- Set `SESSION_SAME_SITE=lax` or `SESSION_SAME_SITE=strict` in production.
- Do not set `SESSION_DOMAIN` to a parent domain that covers the artifact host.
- Set a dedicated `ARTIFACT_URL_SIGNING_KEY`; it must be present, high-entropy, generated per deployment, and different from `APP_KEY`.
- If Reverb is enabled, keep `REVERB_APP_RATE_LIMITING_ENABLED=true`, set a bounded `REVERB_APP_MAX_CONNECTIONS`, and keep `REVERB_PUBLIC_URL`/`REVERB_ALLOWED_ORIGINS` on the app origin only.
- Create the bootstrap System Admin without baking `ARTIFACTFLOW_ADMIN_PASSWORD` into a cached config artifact, then remove or rotate that temporary password after first login.
- Keep artifact storage private and confirm artifact-preview URLs are served only by the artifact runtime.
- Confirm protected branches require **both** required status checks before merge or release: the aggregate `ci-required` check (from `ci.yml`, which folds in the DCO sign-off gate) **and** the `cla` check from the `CLA` workflow (`cla.yml`). The CLA runs in a separate `pull_request_target` workflow, so it can never be part of `ci-required`; requiring only `ci-required` would leave the CLA signature unenforced at merge — the "no CLA, no merge" guarantee depends on this second required check. If the repository is still private on a GitHub plan without branch protection, make it public or move it to a plan that supports branch protection before treating CI as enforced.
- Run the full gate set from `AGENTS.md`, including type coverage, line coverage, browser E2E, production image build, and Trivy image/config scans.
- Do not deploy the repository `docker-compose.yml` as a production stack; it is local-only and intentionally uses development settings.

## Before Publishing the Repository

- Push only `main` and release tags, or publish from a fresh clone. Never use `git push --mirror` or a GitHub repository import against a working repository: AI-agent tooling stores checkpoint refs (for example `refs/codex/turn-diffs/...`) whose trees can contain snapshots of private working files that were never committed to `main`.
- Verify no agent checkpoint refs remain: `git for-each-ref refs/codex refs/claude` must print nothing. Delete leftovers with `git update-ref -d <ref>`, then prune with `git reflog expire --expire=now --all && git gc --prune=now`.
- Run `make publish-guard`.

## Fine To Defer Past Alpha

- Version diff UI.
- Orphan artifact-storage garbage collection.
- Additional search projection/index work once real data volume exists.
- Rector and broader automated refactoring gates.
