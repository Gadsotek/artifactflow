# Security policy

ArtifactFlow is a self-hosted workspace for AI-generated artifacts, backed by
a versioned artifact vault. It handles executable HTML and hostile binary input.
Read the [threat model](THREAT-MODEL.md) before relying on its isolation.

## Report privately

Use [GitHub private vulnerability reporting](https://github.com/Gadsotek/artifactflow/security/advisories/new)
or email [gadsotek@gmail.com](mailto:gadsotek@gmail.com) with subject
`[ArtifactFlow security]`. PGP is available on request. Do not open a public
issue or PR exposing a live vulnerability.

Include the affected version/commit, reproduction steps or proof of concept,
and observed impact. We aim to acknowledge within **3 business days** and
provide an initial assessment within **7 business days**. We coordinate fixes,
disclosure, and credit; anonymity is available on request.

## Scope

Reports should demonstrate impact on authentication, authorization, private
data, origin/sandbox isolation, signed capabilities, server injection, app-origin
XSS, CSRF, parser containment, or secure shipped defaults.

Artifact HTML intentionally executes JavaScript inside its isolated frame.
Execution alone is not an escape. Report paths that exceed the documented
boundary, including unexpected network behavior.

Compromised hosts, deployments contradicting required configuration, ordinary
volumetric DoS without amplification, and missing headers without demonstrated
impact are outside vulnerability scope unless they produce a covered defect.
Unclear setup instructions still deserve a documentation report.

## Deployment requirements

- Use `APP_ENV=production`. Local/testing configuration relaxes protections.
- Keep app and artifact host on distinct HTTPS hostnames with no app cookies
  reaching the artifact host. Ports alone do not isolate cookies.
- Use restricted artifact-host database grants and separate database limiter
  stores. Redis, Memcached, and DynamoDB limiter aliases are not supported in
  the current production contract.
- Keep private storage and dedicated processors isolated. Enable PDF/XLSX/DOCX
  only after their deployment checks; DOCX also requires PDF.
- Verify image digests/attestations, deliverable mail, recovery custody, and
  tested backups through the [release checklist](RELEASE-CHECKLIST.md).

## Known limits

Prompt injection remains a client risk. MCP scopes, live authorization, Editor
ceilings, concurrency, per-principal rate limits, and scanning enforce server
operations. A client's human approval screen is not a server guarantee.

HTML self-navigation may send embedded or user-entered data externally;
WebRTC blocking is browser-dependent. Revocation cannot erase delivered bytes.
PDF/DOCX-PDF viewing is download-equivalent. Scanning and previews are not
antivirus or redaction certificates. See the [threat model](THREAT-MODEL.md).

There is no general account erasure/anonymization workflow. Authorship records
and backups require an operator retention policy. Supported workspace deletion
is deliberately constrained; do not remove records directly to bypass it.

## Agent hooks

Tracked `.claude/` and `.codex/` settings can run repository-shipped pre-action
hooks when those tools trust/activate the project. Inspect
[their source and policy](scripts/ai-hooks/README.md) before using them.
They gate agent actions, protect secrets and data, and require approval for
protected changes and pushes; they do not add capabilities. Verify with
`make ai-hooks-test`. Their local telemetry excludes prompt, command, path,
and credential contents.

## Supported versions

ArtifactFlow is pre-1.0. Security fixes target the latest `main`; tagged releases
exist. Pin a reviewed revision or verified image digest and update forward.
Docker is the supported runtime: bundled Compose for local development,
production images with separate roles for deployment. Bare-metal installs are
not supported. No independent third-party security audit has been completed.
