# Access, accounts, and agents

[Threat model](../../THREAT-MODEL.md) · [MCP setup](../operations/mcp.md)

## Human access

`PageAccess` and application handlers enforce page/workspace authority on every
surface. Queries first narrow visibility, then apply exact authorization.
Writes reauthorize under the required locks. A hidden button is not a boundary.

System Admin controls installation settings and accounts, not other people's
content. It grants no implicit workspace, search, preview, or page access.
Registered human names, emails, and UIDs are intentionally discoverable to
authenticated coworkers. Service accounts are excluded from human pickers.
Knowing an identifier never grants permission to use it.

Workspace categories are discoverable to members. A page-only grant reveals
the granted page's workspace and attached taxonomy, not unused categories or
siblings. Tags are stored globally but discovered only through visible pages.
Reader/Editor page grants may target registered humans; page Admin requires
membership in the page workspace.

Shared workspaces support three levels, downward membership inheritance,
local exclusions, and direct roles. Parent/sibling authority never flows upward
or sideways. Hierarchy writes serialize, recheck impact, and retire affected
grants, invitations, preview revisions, and presence. Exact selected MCP scopes
do not grow with descendants. The [nested workspace decision](../architecture/nested-workspaces.md)
owns the full mutation and non-disclosure contract.

## Authentication and recovery

Missing migrations fail closed before database sessions or MCP bearer lookup.
The readiness cache is bound to the exact deployed migration-file manifest.
MCP gets retryable `installation_not_ready` with 503 and `Retry-After: 30`.
The artifact origin exposes no setup state.

Login limits combine email/IP per minute, source IP per minute, and account
per hour. Password recovery and TOTP have separate budgets. Shared production
database limiter stores separate app and artifact-host credentials.

TOTP secrets are `APP_KEY`-encrypted; recovery codes are one-way hashes.
First-admin enrollment uses the just-validated password only for its bounded
window, including confirmation. Expiry invalidates pending enrollment material.
Administration requires fresh TOTP or a recovery code; password and trusted
device do not bypass it. Human MCP-token creation requires password plus fresh
TOTP. Security changes advance live authentication revisions.

Trusted-device cookies are revocable bearer credentials. Theft can bypass TOTP
until expiry/revocation. Password reset invalidates browser sessions and trusted
devices but preserves independent MCP tokens, which must be revoked separately
after suspected compromise. See [account recovery](../operations/accounts.md).

Optional Turnstile verifies exact hostname and form action before password
checking/reset work. Failure is closed; errors log no tokens or IPs. With both
keys absent there is no Cloudflare data flow. When enabled, browser signals and
token/IP verification reach Cloudflare, and its failure may block login/reset.
The existing rate limits remain required. See [production setup](../operations/production.md#optional-turnstile).

## MCP authority and prompt injection

Tokens intersect allowed operations, exact workspace scope, and live principal
access. Workspace/page Admin is capped at Editor. MCP cannot administer
workspaces, manage grants, archive, hard-delete, or transfer ownership.
All-workspaces scope deliberately follows the principal's future live reach.

Every present user-controlled or extracted string uses a field-level
`artifactflow.untrusted_data` envelope. Absent optional values are omitted.
Metadata-only reads still authorize, but skip payload access and make no claim
that retained bytes are readable. Images can contain visual instructions; PDF
text can include invisible material. Framing is advisory and prompt injection
remains a client risk.

Read content never authorizes a later write. A write requires operation scope,
live authority, current content/metadata revisions as appropriate, validation,
scanner success, and rate-limit budget. Tokens and writes are limited per
principal across tokens, with a separate source-IP pre-authentication budget.

In-flight tools and revocation share a credential-scoped PostgreSQL advisory
lease. Tools reload token/principal state under a shared lease held through the
operation; revocation takes it exclusively. A tool commits before revocation
or fails after it. Account changes and issuance serialize on the user row;
two-factor disable uses `FOR NO KEY UPDATE` while draining token leases to
avoid deadlocks with in-flight foreign-key checks.

Description writes require both the observed current-version UID and metadata
revision. Organization is a separate scope. Binary ingestion requires create
or update plus upload scope before decoding/processor work. ArtifactFlow never
fetches a client-supplied URL or filesystem path.

MCP share creation requires `mcp:share`, exact scope, ownership, live edit
authority, and the workspace's editor-sharing setting. It grants no inventory,
revoke, or access-management authority. The once-returned URL is a bearer
secret and must not enter artifacts, prompts, traces, or logs.

## Provenance and external references

Observed ingest actor/client/hash facts are separate from producer claims.
Claims may be partial and remain self-reported. Client names, URL hosts, and
model-shaped strings are not provider attestations. Completeness measures
populated fields, not truth.

Strings are bounded, escaped, and scanned for obvious credentials. References
require HTTPS without authority credentials and are never fetched. They inherit
page authorization and stay out of search, audit, events, and logs. They remain
sensitive database/backup contents. Ordinary version pruning preserves them;
hard deletion removes them. There is no automatic reference-redaction job.

Initialization rejects non-string nested `clientInfo` and serializes retention
of the newest 64 client reports per token. Eviction removes attribution, not
token authority. Full-text provenance labels are capped at 256 pairs; structured
authorized filters remain exhaustive. Restore resolves retained-byte origin
without relabeling the restoring actor as producer.

## External sharing

Only the current version of one page is presented. Expiring links have a
required expiry; one-time links have no expiry and are consumed by an explicit
locked open POST. GET/unfurl does not spend the link. Raw secrets travel in a
fragment, are removed before exchange, and are persisted only as hashes.

The anonymous viewer uses distinct pending/view credentials and a per-window
`sessionStorage` proof. An app login adds no share authority. Every load and
preview issuance rechecks policy, live share/session state, page lifecycle,
workspace, and access revision. Uniform unavailable responses hide reasons.
Public rate limits include a source-wide budget to resist selector rotation.

Markdown disables private wiki links; HTML/images keep their normal sandbox;
XLSX exposes only its manifest; DOCX exposes only its validated PDF. Native
PDF viewing is download-equivalent. No anonymous original Office download,
search, history, taxonomy, or editing endpoint is added.

Possession is not recipient identity. Recipients can copy delivered content
or deliberately clone viewer state. The full contract is
[external sharing](../architecture/external-sharing.md).
