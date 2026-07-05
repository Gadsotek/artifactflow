# Security Policy

ArtifactFlow is a **security-sensitive** application: its core function is to store and render
**untrusted, AI-generated HTML** and Markdown for multiple tenants. We take vulnerability reports
seriously and appreciate responsible disclosure.

## Reporting a vulnerability

**Please do not open a public issue for security problems.** Public issues expose other users
before a fix is available.

Report privately via one of:

1. **GitHub Security Advisories** (preferred): use the repository's **Security → Report a
   vulnerability** ("Report a vulnerability" / private advisory) form.
2. **Email**: `gadsotek@gmail.com` with subject `[ArtifactFlow security]`. PGP available on
   request.

Please include: a description, the affected version/commit, reproduction steps or a proof of
concept, and the impact you observed. If you have a suggested fix, even better.

### What to expect
- **Acknowledgement** within 3 business days.
- An initial assessment (severity, scope) within 7 business days.
- We will keep you updated, credit you in the advisory (unless you prefer to remain anonymous),
  and coordinate a disclosure timeline with you. Please allow a reasonable window to ship a fix
  before any public disclosure.

## Scope

In scope: authentication/authorization and tenant isolation, the artifact rendering sandbox and
origin isolation, signed-URL handling, server-side injection (SQLi/SSRF/path traversal), stored
or reflected XSS on the application origin, CSRF, and insecure defaults in the shipped
configuration.

Out of scope (unless they lead to one of the above): findings that require an already-compromised
host or a misconfigured deployment that contradicts the documented setup; rate-limiting/volumetric
DoS without amplification; missing hardening headers with no demonstrated impact; and the artifact
sandbox executing the script content it is *designed* to execute in isolation (that is the
intended model; see below).

## The artifact sandbox: understand the model first

A core, intentional design property is that **artifact HTML/JS executes**; it is contained by
**origin isolation + an iframe sandbox + a strict Content-Security-Policy**, not by trying to
sanitize the script away. Before reporting "the artifact ran JavaScript," please read
[`THREAT-MODEL.md`](THREAT-MODEL.md), which documents exactly which controls are load-bearing.
The interesting reports are ones that **escape** that containment (reach the application origin,
another tenant's data, or the network), not ones that execute inside it.

## Self-hosting note

ArtifactFlow is self-hostable, so deployment configuration is part of its security posture. The
isolation guarantees depend on serving the app and the artifact host on **distinct origins** and
on the settings in [`RELEASE-CHECKLIST.md`](RELEASE-CHECKLIST.md). A report that only reproduces
under a configuration that violates that checklist is a documentation issue, not a vulnerability.
But tell us, because the docs should make the safe path the easy one.

## Known limitations and operational requirements

Honest boundaries a self-hoster should understand before relying on ArtifactFlow:

- **The fail-closed boot gate requires `APP_ENV=production`.** The production safety checks
  (overlapping origins, weak/reused signing key, non-HTTPS, public artifact storage, insecure
  sessions, trusted-proxy sanity, …) run only when `APP_ENV` resolves to `production`; deploying
  internet-facing with `APP_ENV=local`/`testing` disables the gate *and* relaxes the app CSP. The
  default when `APP_ENV` is unset is `production`, and any value outside `{local, testing, build,
  production}` aborts the boot — so this only bites if you explicitly ship a development env value.
- **Prompt injection is not solved.** Page content is treated as untrusted *data* and structurally
  framed (the MCP untrusted-data envelope, strict output escaping), but an AI client you point at
  ArtifactFlow can still be influenced by content it reads. Do not grant a write-capable MCP token
  to an agent you also feed untrusted external content; rely on scoping, the audit trail, and
  revert for recovery.
- **MCP write approval, if any, is a property of your client, not the server.** Server-side write
  controls are: token workspace-scope + hard Editor cap, a fresh-TOTP step-up to mint a token,
  per-token rate limiting, the unconditional on-save content scanner, and the audit trail. A
  different MCP client may not prompt a human before writing.
- **Run the app and artifact host with distinct database grants.** They share one image today; give
  the artifact host a least-privilege (ideally read-only, pages/page_versions-scoped) DB role and
  network-segment it, so a compromise of the untrusted-content surface cannot write app data.
- **Hard account/workspace deletion is not supported.** User-authorship foreign keys
  (page/version/grant authors and owners) have no `ON DELETE` action, so the database
  refuses to drop a user that still authored content, and there is no application
  delete/anonymize flow, so authored users and workspaces persist. If you have a
  right-to-erasure obligation, handle it out of band until a supported flow exists.
- **Search relevance depends on an application-maintained index.** The full-text `search_vector`
  denormalizes owner/workspace/category/tag names and is refreshed by application code on every
  write path (no database trigger backstop). If you extend the code, preserve that refresh contract
  or rebuild with `php artisan artifactflow:reindex-search`.

## Supported versions

This project is pre-1.0; security fixes are applied to the latest `main`. Pin a commit and update
forward for fixes until tagged releases exist. Supported deployments are Docker-first: use the
bundled Compose stack for local evaluation and development, or deploy the production image as the
separate runtime roles documented in the operations guide. The bundled `docker-compose.yml` is not
a production template. Bare-metal/`composer install` deployments are not supported.
