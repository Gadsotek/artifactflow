# ArtifactFlow Operations

Last updated: 2026-07-23

## Local Runtime

The local stack follows the architecture document:

| Service | Role |
| --- | --- |
| `app` | Main Laravel HTTP origin. |
| `artifact-host` | Same code image, separate stateless artifact-serving origin. |
| `image-parser` | Minimal internal-only PNG/JPEG decoder and normalizer; no app source, database, artifact storage, or public port. |
| `worker` | Queue worker (`queue:work`). Scans, projections, and audit side effects run synchronously inside the write transaction; the only queued work today is outbound mail. |
| `scheduler` | Laravel scheduler loop (`schedule:work`): outbox dispatch and the nightly retention jobs. |
| `db` | PostgreSQL 17 for app data, queues, search, and event outbox. |
| `edge` | Optional local Caddy reverse proxy for named host routing. |

Start the core stack:

```sh
make up
```

This idempotently provisions distinct local `ARTIFACT_URL_SIGNING_KEY` and
`IMAGE_PARSER_SHARED_SECRET` values in `.env` before either consumer starts.

Start the full local stack with Vite, Caddy edge routing, Adminer, and Mailpit:

```sh
make up-local
```

Default direct ports:

| Endpoint | URL |
| --- | --- |
| Main app | `http://localhost:18080` |
| Artifact host | `http://127.0.0.1:18081` |
| Mailpit | `http://localhost:18033` |
| Adminer | `http://localhost:18089` |

The artifact host intentionally uses `127.0.0.1` while the app uses `localhost`. Cookies ignore the port (RFC 6265), so two origins on the same host that differ only by port would send the app session cookie along with every artifact request; a different host is what keeps app cookies off the artifact origin. Keep the two hosts different if you customise these URLs (`php artisan artifactflow:doctor` fails when they collide).

The repository `docker-compose.yml` is a local development stack. It intentionally uses loopback ports, local-only credentials, `APP_DEBUG=true`, and non-TLS PostgreSQL (`DB_SSLMODE=disable`). Do not use it as a production compose template.

Optional local hostnames through the Caddy edge (`make up-local`):

```text
127.0.0.1 app.artifactflow.test
127.0.0.1 artifacts.artifactflow.test
```

Then open:

```text
http://app.artifactflow.test:18085
http://artifacts.artifactflow.test:18085
```

## First User Setup

Registration is disabled by default. Create a verified login user from the app container. Put the password in a mounted secret file and expose only its path to the command, so the secret never lands in a process listing, shell history, or a long-lived environment variable:

```sh
docker compose exec -T \
  -e ARTIFACTFLOW_CREATE_USER_PASSWORD_FILE=/run/secrets/artifactflow_create_user_password \
  app \
  php artisan artifactflow:create-user \
  --name="Admin User" \
  --email="admin@example.test"
```

> Provision `/run/secrets/artifactflow_create_user_password` through the deployment's secret-file mechanism before running the command, and remove or unmount it afterwards. Avoid `--password="..."` and `-e VAR="value"` with an inline value: both place the secret in the `docker compose exec` argv, where it is visible to other users via `ps`/`/proc` and may be written to shell history.

The password must be at least 12 characters. The command creates a normal verified user, provisions their personal workspace, and records audit/domain events.

Reset a user's password from the app container when an operator recovery path is needed. Use a separate one-shot secret file:

```sh
docker compose exec -T \
  -e ARTIFACTFLOW_RESET_PASSWORD_FILE=/run/secrets/artifactflow_reset_password \
  app \
  php artisan artifactflow:reset-password \
  --email="admin@example.test"
```

The command rotates the user's password and remember token, invalidates database-backed sessions and trusted devices for that user, and records audit/domain events without storing or printing the password. Existing MCP tokens are independent credentials and are deliberately preserved. When the reset responds to suspected credential compromise, revoke them separately:

```sh
docker compose exec -T app php artisan artifactflow:mcp-token-revoke \
  --email="admin@example.test"
```

Create or promote the deployment system admin when needed. Use its dedicated one-shot secret file; the production boot gate rejects the plain `ARTIFACTFLOW_ADMIN_PASSWORD` variable before this command can run:

```sh
docker compose exec -T \
  -e ARTIFACTFLOW_ADMIN_PASSWORD_FILE=/run/secrets/artifactflow_admin_password \
  app \
  php artisan artifactflow:bootstrap-admin \
  --name="Admin User" \
  --email="admin@example.test"
```

Fresh installs require System Admins to enroll TOTP 2FA by default. The password they just used to sign in counts as confirmation for `TWO_FACTOR_ENROLLMENT_PASSWORD_TIMEOUT_SECONDS` (default 180 seconds); the security screen shows the live deadline, and both starting and confirming enrollment must occur inside it. At expiry the browser returns to password confirmation, the pending QR/secret becomes unusable, and restarting enrollment after confirmation generates a fresh one. A System Admin can require 2FA for all users from the installation settings screen. If an operator loses the only admin's second factor, use the console-only break-glass path:

Entering Administration requires a live authenticator code or an unused recovery code; the account password and a trusted-device cookie do not bypass this prompt. A successful proof is cached only for `AUTH_ADMIN_TWO_FACTOR_TIMEOUT` (900 seconds by default). Enabling or disabling two-factor authentication, rotating recovery codes, or revoking all trusted devices advances the account authentication revision and rotates the acting browser session; other sessions fail closed on their next request.

```sh
docker compose exec -T app php artisan artifactflow:disable-2fa \
  --email="admin@example.test" \
  --force \
  --reason="lost device during restore drill" \
  --clear-enforcement
```

`--clear-enforcement` clears the user's per-account 2FA requirement and the install-level System Admin/org-wide requirements so recovery cannot loop back into forced enrollment. The command deletes trusted devices, records audit/domain events with scalar operator context, and must not print TOTP secrets, recovery codes, trusted-device tokens, or token hashes.

After a restore or `APP_KEY` incident, diagnose encrypted TOTP secret readability:

```sh
docker compose exec -T app php artisan artifactflow:diagnose-2fa
```

Use `--json` for automation. The command reports aggregate `checked`, `readable`, and `unreadable` counts only. `APP_KEY` is custody-critical for TOTP secrets and encrypted cookies; rotating or losing it makes TOTP secrets unreadable and causes trusted-device cookies to fail closed to the normal challenge. Recovery codes survive because they are stored only as password hashes.

**Trusted-device tradeoff.** "Remember this device" issues an httpOnly, secure cookie holding a high-entropy token (stored server-side only as a SHA-256 hash) that skips the TOTP challenge for `TWO_FACTOR_TRUSTED_DEVICE_DAYS` (default 30). The cookie is a bearer token: it is not re-bound to the browser or IP on use, so anyone who exfiltrates it can bypass 2FA for that account until it expires or is revoked. This is the standard tradeoff for the feature; operators with stricter requirements should shorten the TTL, and users can revoke trusted devices from their 2FA settings at any time.

Seed the Hello World Markdown and HTML artifact demo pages for an existing user:

```sh
docker compose exec -T app php artisan artifactflow:seed-demo-content \
  --email="admin@example.test"
```

## Search Maintenance

When page text extraction changes, run the search reindex command to backfill stored page versions from the private artifacts disk and rebuild page search vectors:

```sh
make reindex-search
```

The default command reindexes only each page's current version, which is the version used by search. Pass operator options through `REINDEX_ARGS`, for example `make reindex-search REINDEX_ARGS='--dry-run'`, `make reindex-search REINDEX_ARGS='--page=<uid>'`, or `make reindex-search REINDEX_ARGS='--all-versions'`. Historic (non-current) versions keep only the bounded `source_text`; their `extracted_text` is deliberately cleared when a newer version becomes current, and reindexing does not resurrect it, because restore/revert and reindex re-extract from the stored artifact file. The command prints aggregate counts only and must not output private page content, artifact source, or signed URLs.

## Storage Counters

Workspace storage quotas are enforced against the maintained `workspaces.used_storage_bytes` counter, which the page-version create/delete/move handlers update inside the same transactions under the workspace row lock. If drift is ever suspected (for example after manual database surgery or a partial restore), reconcile the counters against the authoritative per-version byte sizes:

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:recount-storage'
```

The command reports only aggregate `workspaces=` and `corrected=` counts.

## Orphaned Artifact Files

`artifactflow:verify-artifacts` checks the row-to-file direction (every `page_versions` row still has its blob). The reverse direction — blob files with no referencing version row — is handled by the orphan reaper. Orphans can arise when a hard delete commits the row removal but the best-effort disk cleanup outside the transaction fails (audited as `page.artifact_delete_failed`), or when a version write is interrupted after the blob is stored but before its row commits.

Preview first (report-only, never deletes):

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:prune-orphan-artifacts'
```

Then delete once the report looks right:

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:prune-orphan-artifacts --delete'
```

Files younger than `--min-age-hours` (default 24) are always skipped so the reaper cannot race an in-flight append, whose blob is written just before its version row commits. The command reports only aggregate `scanned=`, `orphans=`, `deleted=`, and `recent_skipped=` counts plus a capped sample of orphan paths; it never prints artifact content or signed URLs.

## MCP Access

MCP is served by the official Laravel MCP transport only on the app runtime at `POST /mcp`. The artifact-host runtime must return not found for the same path. MCP clients authenticate with bearer tokens issued either from a human user's account settings or from the service-account CLI path. Every tool call still uses the normal workspace/page policies, scanners, optimistic concurrency checks, and audit/domain-event trail.

The app checks its bundled migration-file manifest before MCP authentication. If a deployed release contains an unrecorded migration, MCP returns HTTP 503 with a retryable JSON-RPC `installation_not_ready` error and `Retry-After: 30`; it does not look up or consume the bearer token. Run the approved migration workflow (`make migrate` for this Compose stack), then retry—the same token works once the schema is current. The same check catches a new migration appearing in a long-running, live-mounted process. It cannot detect source or an image that was pulled elsewhere but never started: an old running process knows only the migration manifest in its own deployed release, so deployment automation must still replace every replica and run migrations in the intended order.

Human users create their own MCP tokens from Security -> MCP tokens. Creation requires the account to have TOTP two-factor authentication enabled, then requires the current password and a fresh authenticator code in the create request. The plaintext token is shown once. Token list and revoke are scoped to the signed-in user's own account; revocation does not require the strong create step-up so rotation stays cheap. Workspace scope is an explicit choice: select one or more workspaces to bind the token to that smaller read/write ceiling, or check "All workspaces" to grant every workspace the account can reach now and any it joins in future. An empty selection with "All workspaces" unchecked is rejected, never silently minted as an all-workspaces token.

Per-user token reach follows the user's live workspace memberships and the token's optional workspace scope. A workspace-scoped token cannot discover workspaces or taxonomy, search, read, create, organize, upload, update content or descriptions, or revert anything outside that scope, even if the principal has broader browser access. System Admin is installation/account authority only and never grants workspace or page content access. MCP further de-elevates workspace Admin to Editor, caps Admin page grants to Editor, and removes page-admin capabilities such as manage access, archive, hard delete, change access mode, and transfer ownership. MCP cannot create or administer workspaces.

To configure local clients with a token, run:

```sh
./scripts/connect-mcp.sh
```

The connector discovers the standard Claude Desktop config, the active Claude Code user config (including `CLAUDE_CONFIG_DIR`), conventional sibling Claude Code config directories, the active/default Codex user config (including `CODEX_HOME`), conventional sibling Codex homes, and existing Codex profile overlays. It prints every discovered or creatable target and requires an explicit choice of target numbers or `all`; no config is silently selected. Existing files are backed up and merged, and each result is restricted to mode `0600`. For automation, set `MCP_URL`, `MCP_TOKEN`, and `MCP_TARGETS` (`all` or the comma-separated target numbers printed by the script).

Codex connects to the authenticated Streamable HTTP endpoint directly. Claude configs continue to use `npx mcp-remote` because the supported Desktop JSON path cannot supply ArtifactFlow's static bearer token natively; the connector keeps one compatible Claude entry across Desktop and Code. When a Claude target is selected, the connector requires Node.js 20.18.1 or newer (`node`, `npm`, and `npx`), verifies the committed lock's reviewed SHA-256 fingerprint, and installs the complete npm integrity lock with `npm ci --engine-strict --ignore-scripts`. Each lock fingerprint gets its own user-data directory, so a failed upgrade cannot destroy the install referenced by existing Claude configs. The generated config fixes the reviewed directory as its working directory and runs the exact `mcp-remote@0.1.38` package with `npx --offline --no-install` plus a dedicated empty runtime cache; a missing local package therefore fails closed instead of resolving mutable `latest`, searching the active project, or executing an independently cached graph. Nightly automation audits the lock, verifies every registry integrity entry, performs a real authenticated loopback MCP exchange, and proves the missing-local-package case fails. Set `MCP_BRIDGE_HOME` only when the versioned locked-bridge root needs a non-standard user-data location.

The reviewed bridge version and primary tarball integrity are pinned in `scripts/verify-mcp-remote.mjs`, every locked registry package must carry SHA-512 integrity, the nested dependency graph is included in npm audit and Dependabot coverage, and the nightly audit performs a clean locked install plus a real offline `npx` initialize/`tools/list` exchange with an authenticated local MCP fixture. Bridge upgrades are deliberate review changes: update the exact version, regenerate the lock, review the package delta, and update the expected integrity before merging.

This control makes installation reproducible; it does not turn `mcp-remote` into trusted first-party code. The bridge remains an experimental third-party process that receives the MCP bearer token. Keep the token narrowly scoped and short-lived, review proposed bridge upgrades, and remove the bridge when the Claude Desktop path can supply the required authorization natively or ArtifactFlow adopts a compatible first-party authorization flow.

The connector intentionally does not offer project `.mcp.json` or `.codex/config.toml` files. Its generated bridge contains the bearer token, so writing it into repository-level configuration would create a commit and collaborator-disclosure risk. Use the selectable user-level configs instead.

For headless agents, create a service-account token from the app container:

```sh
docker compose exec -T app php artisan artifactflow:mcp-token-create \
  --email="agent@example.test" \
  --name="Architecture Agent" \
  --workspace="<workspace_uid>" \
  --scope="mcp:search" \
  --scope="mcp:read" \
  --scope="mcp:create" \
  --scope="mcp:update" \
  --scope="mcp:organize" \
  --scope="mcp:upload" \
  --scope="mcp:share" \
  --ttl-days=30
```

The command prints the plaintext token once. Store it in the MCP client secret store immediately. It creates or reuses a service account, grants Editor membership to the selected workspaces, stores those workspace UIDs as the token's read/write ceiling, and refuses System Admin users, non-service users, and any service account that holds a workspace Admin membership. It must not print passwords, page content, signed artifact-preview URLs, or existing token hashes.

List or revoke service-account and per-user token metadata without printing token values:

```sh
docker compose exec -T app php artisan artifactflow:mcp-token-list --email="agent@example.test"
docker compose exec -T app php artisan artifactflow:mcp-token-revoke --uid="<mcp_token_uid>"
```

Mint and revoke actions are recorded in domain events and audit entries without storing the plaintext token or token hash in metadata.

Available scopes:

- `mcp:search` lists reachable workspaces and searchable taxonomy, and searches only pages the MCP principal can view within the token's workspace ceiling. `list_taxonomy` returns global tag UIDs visible through searchable pages and workspace-qualified category UIDs from reachable workspaces or individually granted pages; both it and search accept optional `workspace_uid` to narrow within that ceiling. Search snippets additionally require `mcp:read`. Note that `mcp:search` alone is not "harmless": it exposes page titles, taxonomy labels, types, statuses, and update times across everything the principal can reach — metadata that can itself be sensitive. Scope tokens to specific workspaces when the consumer only needs a subset.
- `mcp:read` reads in-scope text content as an explicit untrusted data envelope. For an image page it returns normalized PNG/JPEG derivatives up to the configured `ARTIFACT_MAX_BYTES` (10 MiB by default, hard-capped at 64 MiB — the same read limit as every other page type, expanded by roughly a third once base64-framed) as a standard MCP image content block beside untrusted metadata; a retained derivative above that limit returns `content_too_large` before the bytes are read or base64-expanded. The original upload no longer exists. When the default-off PDF milestone is enabled, PDF read returns only bounded extracted text and safe facts; it never reads or returns the original bytes, signed URLs, storage paths, or processor diagnostics. The server never treats read content or image pixels as authorization for a later write.
- `mcp:create` creates Markdown or single-file HTML pages through the normal page creation handler, including an optional visible `parent_page_uid` and an existing category UID. Creating an image instead uses `create_image` and also requires `mcp:upload`. When PDFs are enabled, `create_pdf` likewise requires `mcp:create` plus `mcp:upload` and exact Editor-capped workspace authority. Non-empty tag names or `category_name` additionally require `mcp:organize` because they can create taxonomy.
- `mcp:update` appends a new Markdown/HTML version through the normal update handler and requires a fresh `base_version_uid`; it powers one-action revert and `update_description`. Description updates require both the fresh `current_version_uid` and separate `metadata_revision` returned by read or search, pass the normal description scanner, refresh full-text search, and cannot change title, owner, hierarchy, category, or tags. `replace_image` and enabled `replace_pdf` operations also require `mcp:upload`; both reject a stale base version before parser work. Reverting retained image/PDF content does not require upload authority because it reuses a retained payload through the normal current processing rules.
- `mcp:organize` powers `organize`, `create_category`, and `create_tag`. `organize` requires the current `metadata_revision` and can change only title, parent, category, or the complete tag set; it cannot change owner, workspace, access, lifecycle, description, or content. Categories remain workspace-local. Tags are installation-wide records, but `list_taxonomy` exposes them only through use on visible pages; the workspace supplied to `create_tag` is an Editor-authority boundary, not tag ownership.
- `mcp:upload` permits binary ingestion only in combination with the page operation scope: `create_image`/enabled `create_pdf` require `mcp:create`, while `replace_image`/enabled `replace_pdf` require `mcp:update`. All accept canonical standard Base64 and reject whitespace, data URLs, and media mismatches before parser admission. Images use the existing isolated normalizer and discard submitted bytes after retaining the normalized derivative; PDFs use the isolated processor and retain the validated original. ArtifactFlow never dereferences a client-supplied URL or filesystem path.
- `mcp:share` creates a one-time or expiring external-share capability for an in-scope page the MCP principal owns and can still edit, only while that workspace's **Allow Editors and page owners to share pages** setting is enabled. It is not access-management authority and grants no inventory or revoke operation. Human and service-account principals follow the same rule; MCP's Editor authority ceiling means an underlying administrator cannot bypass the workspace switch through this tool. `create_external_share` returns the raw bearer URL once; store it only in the intended recipient channel and never copy it into an artifact, metadata, prompt, trace, or log.

### MCP provenance

Content-version write tools may include provenance when the caller knows any safe producer fact. Do
not make the object mandatory in client wrappers, do not discard a known provider or model family
merely because the exact provider model ID is unavailable, and do not fill missing fields by guessing from the MCP client
name. `clientInfo.name=claude-code`, for example, is unverified caller-reported protocol metadata:
it does not prove which implementation submitted the request, nor that Claude, a particular Opus
release, or even an AI model authored the content.

ArtifactFlow rejects non-string nested `clientInfo` fields during initialization and retains only
the newest 64 client-reported transport sessions per MCP access token. Initialization serializes this
per-token pruning so concurrent clients cannot bypass the cap. Evicting an older observation removes
only client-name/version attribution for that transport session; it does not revoke the access token
or turn the transport session identifier into authority.

An exact AI declaration may include both `provider` and the provider-defined `model_id`:

```json
{
  "provenance": {
    "producers": [{
      "kind": "ai",
      "provider": "anthropic",
      "model_id": "claude-opus-5-2-20260715",
      "model_label": "Claude Opus 5.2",
      "model_version": "20260715",
      "generated_at": "2026-08-01T13:42:00.123Z",
      "references": [{
        "kind": "conversation",
        "ref": "abc123",
        "url": "https://claude.ai/chat/abc123"
      }]
    }]
  }
}
```

A partial declaration is equally valid when that is all the caller can support:

```json
{
  "provenance": {
    "producers": [{
      "kind": "ai",
      "provider": "OpenAI",
      "model_label": "GPT-5 family",
      "extensions": [{
        "key": "openai.runtime_product",
        "value": "Codex"
      }]
    }]
  }
}
```

ArtifactFlow preserves the reported provider value, derives a normalized provider key for search,
and reports this claim as `partial`; it does not require or synthesize `model_id`. Successful MCP
content writes return `stored_provenance` with `supplied`, computed completeness, identity precision,
and the direct producer fields that were actually retained. MCP server instructions require the
caller to summarize that receipt to the requesting user.

MCP claims are stored as `self_reported`; the caller cannot select stronger evidence. Every retained
provenance string is scanned for the same obvious credential patterns that block artifact writes.
Extensions are limited to 16 lowercase namespaced key/string-value pairs. They are for short producer
identity metadata only; prompt or chain-of-thought material, credentials, authorization data, signed
URLs, and content/blob payloads are rejected rather than treated as provenance.
External references are optional, HTTPS-only, never fetched, and should not contain signed URLs,
prompt content, or personal data that does not belong in the page's authorization boundary. They
are returned only to principals who can read the page and are excluded from logs, events, audit
metadata, and search. Producer identifiers are also excluded from event and audit metadata.

Authorized searches accept `ai_provider`, `ai_model_query`, and `provenance_scope`:

- `page_origin` matches version one;
- `current_version` matches only the current version's direct producer;
- `any_version` also matches historical and content-pruned version provenance.

The page full-text vector includes at most 256 deterministic, deduplicated provider/model-label
pairs so retained history cannot exceed PostgreSQL's `tsvector` limit. The structured filters above
remain exhaustive across all retained ingests.

The `read` result distinguishes the current ingest actor/client from direct content producers and
effective byte origin. A restore therefore identifies who performed the restore without relabeling
that person/client as the model that produced the restored bytes.

Content scanning remains advisory except for explicit secret and credential patterns, which block writes. Inline script in an HTML artifact is expected; it is recorded as a warning finding and audit trail, not held for human acknowledgement. Descriptions are scanned for obvious secrets and prompt-injection role markers before save. MCP taxonomy names and slugs are user-authored data and are therefore returned inside the same explicit untrusted-data envelope as other user-authored text.

Set `MCP_PRE_AUTH_RATE_LIMIT_PER_MINUTE` to tune the pre-authenticated source-IP ceiling, `MCP_RATE_LIMIT_PER_MINUTE` to tune the authenticated principal ceiling, and `MCP_WRITE_RATE_LIMIT_PER_MINUTE` to tune per-principal create/organize/upload/update-description/update-content/revert/external-share write throughput. Invalid or unauthenticated bearer attempts are bucketed by source IP before token lookup so random bearer rotation cannot create fresh unauthenticated buckets. Authenticated calls and writes are bucketed by the human or service-account principal after token authentication; issuing more tokens does not multiply either allowance. Image normalization keeps independent parser-admission, decoded-pixel, and input-work budgets; temporary parser saturation or unavailability returns a retryable MCP error with `retry_after`. External-share creation additionally consumes the normal per-actor/page `EXTERNAL_SHARE_CREATE_RATE_LIMIT_PER_MINUTE` budget. If many legitimate MCP clients share one NAT or proxy egress IP, size the pre-auth limit for the aggregate caller pool or route trusted clients through distinct egress identities. The official Laravel MCP transport negotiates the protocol during initialization and issues `MCP-Session-Id`; compliant clients return that non-secret identifier automatically, and ArtifactFlow records it in MCP-created version, metadata/description update, restore, and external-share audit metadata. Never place image Base64, submitted image bytes, signed preview URLs, returned external-share URLs, application session cookies, or raw authorization headers in MCP client prompts or logs.

## Mail Delivery

Workspace invitations, password reset links, and other outbound mail default to Laravel's local `log` transport, so a fresh **local** install boots without a third-party mail account (mail is written to the log, not delivered). For real delivery, choose a transport explicitly: set `MAIL_MAILER=resend` with `RESEND_KEY` from your Resend account, or `MAIL_MAILER=smtp` with your SMTP settings, and use a verified sender in `MAIL_FROM_ADDRESS`.

In **production** the `log` and `array` transports are not permitted: they silently discard invitation and password-reset emails, so the boot gate rejects them and the container will not start until `MAIL_MAILER` names a deliverable transport. A deliverable mail transport is therefore a first-boot requirement in production, not an optional add-on, and outbound mail depends on the `worker` role (`queue:work`) actually running — see [Production Runtime](#production-runtime).

Production must also keep `QUEUE_CONNECTION=database`, leave `DB_QUEUE_CONNECTION` unset (or set it to the primary `DB_CONNECTION`), and keep the database queue's `after_commit` option disabled. Invitation state, audit/event rows, and the encrypted delivery job are inserted in one PostgreSQL transaction; the production boot gate and read-only doctor reject queue settings that would split that commit or deliver mail before it completes.

Invitation and password-reset links contain bearer secrets in their URL paths. The bundled Caddy
configuration does not enable access logging, but an external TLS edge, load balancer, APM, or WAF
may log request paths by default. Configure every such layer to redact `/join/*` and reset-link paths,
exclude their query strings, and keep browser/session replay data out of telemetry. Hashing invitation
tokens in PostgreSQL protects a database leak; it cannot protect a plaintext link copied into an
upstream access log.

Invitation creation is rate-limited with `WORKSPACE_INVITATIONS_PER_MINUTE`; invitation acceptance has its own `WORKSPACE_INVITATION_ACCEPTS_PER_MINUTE` budget because it accepts a UID-bearing link. Invitation revoke/delete remains on the authenticated route budget plus workspace-admin authorization.

## Production Runtime

ArtifactFlow supports production self-hosting through its production image and runtime contract. The repository does not ship a one-click production Compose stack: operators provide deployment-specific orchestration, TLS termination, PostgreSQL, secrets, and persistent volumes. The bundled `docker-compose.yml` remains local-only.

Production uses the Caddy/FrankenPHP application image plus the minimal image-parser target:

```sh
make build-prod
docker build --pull --target image-parser --tag artifactflow-image-parser:production .
```

The same production image runs every role. `APP_RUNTIME_ROLE` selects the HTTP surface
(app vs. artifact host) and is validated by the boot gate, but it does **not** by itself
change the container's process: the default entrypoint (`docker/start-production.sh`)
always starts the Caddy + FrankenPHP HTTP server. The `worker` and `scheduler` roles are
long-running non-HTTP processes, so they additionally need their container command
overridden to the matching start script. A `worker`/`scheduler` container left on the
default HTTP entrypoint will pass its health check but serve nothing and run no jobs — set
both the env var and the command:

| Role | `APP_RUNTIME_ROLE` | Container command | Hostname |
| --- | --- | --- | --- |
| `app` | `app` | *(default entrypoint)* | `APP_URL=https://app.example.internal` |
| `artifact-host` | `artifact-host` | *(default entrypoint)* | `ARTIFACT_URL=https://artifacts.example.internal` |
| `worker` | `worker` | `sh /var/www/html/docker/start-worker.sh` | none |
| `scheduler` | `scheduler` | `sh /var/www/html/docker/start-scheduler.sh` | none |

To preserve the cookieless artifact-origin boundary, use only plain `php artisan down` for an artifact-host HTTP role. The `--secret`, `--redirect`, and `--render` variants return directly from Laravel's global maintenance middleware before ArtifactFlow's route security middleware; `--secret` also attempts to set a `laravel_maintenance` cookie on the artifact origin. The artifact-host Caddy role strips every `Set-Cookie` header, including from its separate error-handler chain, so that bypass cookie cannot reach a browser; the app role keeps its legitimate session cookies. Prefer draining or stopping the artifact role through the orchestrator when practical, and never rely on those maintenance variants there.

Run the separately built `image-parser` image as its own service. Give it only
`IMAGE_PARSER_SHARED_SECRET` and `IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS`. Each authenticated request
signs the app's input-byte, output-byte, pixel, and dimension limits; the parser validates them
against fixed protocol ceilings before native decode. It therefore has no independent copy of
the installation's `PAGE_IMAGE_*` settings. The parser needs no application env, database access,
artifact-storage mount, or
public ingress. Put it and every app replica that accepts writes on a private network with no
external route, expose its port only on that network, and apply at least the restrictions used by
local Compose: non-root, read-only root filesystem, all capabilities dropped,
`no-new-privileges`, no-exec temporary storage, and CPU, memory, and PID limits. The application
production image deliberately contains neither GD nor EXIF. Keep one normalization process per
512 MiB parser cgroup: maximum-pixel decode, EXIF rotation, and re-encoding can approach that
per-container memory budget, so prefork workers can OOM-kill only part of the pool while its health
endpoint remains green. The parser entrypoint refuses `PHP_CLI_SERVER_WORKERS` values other than
one. Every app replica uses the shared rate-limit cache for a single non-blocking normalization
slot; a busy slot returns 503 immediately instead of queueing for the parser timeout. Every
dispatch consumes both the image's exact pixel count and its input-work charge from per-principal
and installation-wide one-minute budgets, including completed parser rejections. Input work counts
the raw compressed bytes, metadata bytes, and chunk/marker fan-out independently. Only failures
proven to occur before dispatch are refunded. A connection, timeout, or response-stream failure
with uncertain parser state retains the existing slot lease, whose total TTL is the parser timeout
plus a fixed expiry margin. The parser process also has a 15-second execution ceiling. Monitor
repeated busy responses alongside `image_parser.request_failed` rather than shortening that lease.
App replicas request
identity encoding and incrementally stop a parser response after the signed output-byte limit plus
one sentinel byte, so proxies on the private path must not compress parser responses. Extra parser
replicas may provide failover, but the shared slot intentionally keeps total
normalization concurrency at one; do not increase it or enable unbounded autoscaling without a new
adversarial memory/CPU benchmark and an updated admission design.

The `app` and `artifact-host` containers must mount the **same persistent private artifact
volume at the same `ARTIFACT_STORAGE_ROOT` path**. The app writes page-version bytes and the
artifact host serves those exact bytes after signature and access checks; separate anonymous
image volumes produce successful saves followed by 404 previews. Mount the app side read/write
and the artifact-host side read-only when the storage driver and orchestrator support it. Keep
the shared mount private and outside the image's public web root.

The `worker` runs `queue:work` (outbound mail is the only queued work today) and the
`scheduler` runs `schedule:work`, which drives `artifactflow:dispatch-domain-events` (the
outbox relay) and the nightly `prune-domain-events`, `prune-credentials`,
`prune-external-shares`, and `prune-rate-limit-cache` jobs. Skipping the scheduler halts
outbox dispatch and retention (records accumulate undispatched or expired rather than
being lost); skipping the worker leaves invitation and password-reset emails queued but
never delivered. The local `docker-compose.yml` `worker` and `scheduler` services show
the exact `command:` override to mirror. See
[Mail Delivery](#mail-delivery) for why the worker is required in practice.

Production must use separate origins for the app and artifact host. Do not serve uploaded artifact HTML from the main app origin.

### First boot and configuration

The production image bakes `APP_ENV=production`, and the entrypoint runs `php artisan
config:cache` — which boots the fail-closed production security gate — before it serves any
request. Production configuration is supplied as **environment variables** (from your
orchestrator or secret manager), not by editing a `.env` file inside the immutable image.
Every value the gate requires (both artifact HTTPS origins, a dedicated
`ARTIFACT_URL_SIGNING_KEY`, `APP_KEY`, and, when `IMAGE_PARSER_ENABLED=true` on the `app` runtime
role, a pure internal `IMAGE_PARSER_URL` plus separate strong `IMAGE_PARSER_SHARED_SECRET`,
`DB_SSLMODE=verify-full` + `DB_SSLROOTCERT`, a
deliverable `MAIL_MAILER`, a scoped `TRUSTED_PROXIES`, and secure session settings) must be
present **before first boot**. If any are missing, the container exits and restarts rather
than starting in an unsafe state; the log line names the failing check.

Because the app container will not stay up until the gate passes, run the installer as a
one-off container (or `docker compose run`) once the required env vars are in place, rather
than expecting to `exec` into a running container:

```sh
# ARTIFACTFLOW_ADMIN_PASSWORD_FILE=/run/secrets/af_admin_password in the environment,
# and every boot-gate env var set, run against your deployed database:
docker run --rm --env-file <your-production-env> <your-image> \
  php artisan artifactflow:install --env=production --name='Ops' --email='ops@example.test'
docker run --rm --env-file <your-production-env> <your-image> \
  php artisan artifactflow:doctor
```

On the immutable image the installer generates no keys and writes no `.env` — you provide
keys as env vars — so its production job is to run migrations and create the first System
Admin. Generate a signing key out of band with `php -r 'echo "base64:".base64_encode(random_bytes(32));'`
(kept distinct from `APP_KEY`) and a separate parser secret with the same command. Store both in
your secret manager; the parser and only app-role replicas that accept image writes receive the
same parser secret. Artifact-host, worker, and scheduler roles must receive an empty parser secret;
their production boot gate rejects a non-empty value.

Parser requests and responses are HMAC authenticated and nonce-bound, but plain HTTP does not
encrypt the private link. The default `http://image-parser:8080` is appropriate only inside one
trusted, internal-only container network. For a cross-host parser, terminate mutually
authenticated TLS on both ends and point `IMAGE_PARSER_URL` at that protected origin; never expose
the bare parser listener to a shared or public network.

PostgreSQL transport must verify the server identity in production. Set `DB_SSLMODE=verify-full` and mount a trusted CA bundle or database CA, then point `DB_SSLROOTCERT` at that file. The production boot guard rejects `disable`, `allow`, `prefer`, `require`, and `verify-ca` because those modes either permit cleartext fallback or skip hostname verification.

If you set `SESSION_DOMAIN`, it must not cover the artifact host. A broad parent domain such as `.example.internal` can send app cookies to `artifacts.example.internal`; use a host-only app session cookie or an app-only domain instead.

Any outer TLS proxy, CDN, or ingress must also leave `Set-Cookie` absent on the artifact hostname. The artifact-role Caddy process removes cookies produced by Caddy, PHP, and its error-handler chain, but it cannot remove a header appended by a downstream edge after the response leaves the container.

HTTP Strict Transport Security is sent on every response with a two-year `max-age` (tune with `HSTS_MAX_AGE`). The `includeSubDomains` and `preload` directives are **opt-in** because both reach past the app host and are hard to undo — a preload submission is a near-permanent commitment that forces HTTPS on every sibling subdomain, including the artifact host. Enable them only once every subdomain is HTTPS-only: set `HSTS_INCLUDE_SUBDOMAINS=true` and `HSTS_PRELOAD=true` for the app's (PHP) responses, and mirror the same value into `CADDY_HSTS` (for example `max-age=63072000; includeSubDomains; preload`) so the Caddy fallback used for static files matches. Left unset, both default to a safe host-scoped policy.

`TRUSTED_PROXIES` must name the real TLS edge so the app derives the client IP from `X-Forwarded-For` rather than the proxy's own address. Set it to the edge's address(es) or CIDR; the boot gate rejects an empty value, `*`, and address-space-wide ranges (`0.0.0.0/0`, the default Docker `172.16.0.0/12`). The special value `REMOTE_ADDR` trusts whatever connects directly — the immediate peer — as the proxy, which is safe **only** when the app port is reachable exclusively through the edge. If the app is directly reachable by untrusted clients under `REMOTE_ADDR`, any of them can forge `X-Forwarded-For` and defeat the IP-keyed rate limiters and audit trail; `php artisan artifactflow:doctor` emits a warning whenever production trusts `REMOTE_ADDR` so that network-isolation assumption is a deliberate choice.

`CACHE_STORE` must be shared by every app replica. Database, Redis, Memcached, and DynamoDB are
shared-capable for ordinary application caching; `array`, `null`, `file`, undefined, and unknown
drivers are not. Rate limiting has a stricter cross-runtime credential boundary: the shipped
production configuration currently supports only the dedicated database limiter stores described
below. A node-local file cache is not acceptable even when it persists across requests: round-robin
traffic would receive an independent login, 2FA, reset, preview, and MCP budget on every replica.

The anonymous external-share surface has both a per-source/per-selector/per-operation budget and a
separate per-source ceiling across all selectors and operations. Rotating random selectors therefore cannot create an
unbounded stream of fresh limiter buckets. Database cache stores do not eagerly delete expired
rows, so the scheduler runs `artifactflow:prune-rate-limit-cache` nightly. It prunes the configured
application and artifact-host database limiter stores using the `(expiration, key)` indexes and a
matching forward cursor; non-database stores are skipped. Preview with `--dry-run`.

Production defaults `CACHE_LIMITER=database_limiter` for app, worker, and scheduler processes, and
`ARTIFACT_CACHE_LIMITER=database_artifact_limiter` for `APP_RUNTIME_ROLE=artifact-host`. They use the
same PostgreSQL database but separate `rate_limit_cache*` and `artifact_rate_limit_cache*` tables.
The production boot gate refuses an artifact runtime that selects the application store and rejects
database limiter aliases using the same table name, regardless of their connection alias. Redis,
Memcached, and DynamoDB cache aliases
fail closed for rate limiting even when their names or prefixes differ: Laravel configuration cannot
prove Redis ACL key patterns, Memcached network/credential isolation, or DynamoDB IAM scope. Supporting
one of those limiter backends requires a future backend-specific runtime proof. Changing request
fingerprints does not substitute for isolating the counter store from the artifact-host credential.

When upgrading an existing production deployment, apply migrations before serving traffic with the
new image. The migration creates both limiter tables, both lock tables, and their `(expiration, key)`
indexes. Set `CACHE_LIMITER=database_limiter` and
`ARTIFACT_CACHE_LIMITER=database_artifact_limiter` on every runtime. The optional
`DB_RATE_LIMIT_CACHE_TABLE`, `DB_RATE_LIMIT_CACHE_LOCK_TABLE`,
`DB_ARTIFACT_RATE_LIMIT_CACHE_TABLE`, and `DB_ARTIFACT_RATE_LIMIT_CACHE_LOCK_TABLE` overrides retain
the names shown in `.env.production.example` by default; application and artifact table names must
remain distinct. After migration, apply the grant manifest below as the database owner. Inject the
resulting restricted role through the artifact-host container's existing `DB_USERNAME` and
`DB_PASSWORD` variables only; app, worker, and scheduler keep the application-role credentials.

### Artifact-host database grants

Use a distinct PostgreSQL role for `APP_RUNTIME_ROLE=artifact-host`. The exact reviewed grant
manifest is [`docs/operations/artifact-host-database-grants.sql`](operations/artifact-host-database-grants.sql).
The role reads only `pages`, `page_versions`, external-share/session state, and installation policy.
External-share resolution deliberately holds `SELECT FOR UPDATE` locks through response
materialization so a concurrent revocation cannot commit before stale bytes are served; PostgreSQL
therefore needs column-level `UPDATE (updated_at)` privilege on those three locked tables even though
the artifact-host code never issues an update. Its limiter needs read/write access to
`artifact_rate_limit_cache`. It does not need the application `cache` or `rate_limit_cache` tables,
either limiter lock table, queues, sessions, users, workspaces, audit/event tables,
schema creation, or sequence privileges. Grant database `CONNECT` separately according to your
database naming and role-management policy, keep this as a standalone role with no broader role
memberships, and keep the artifact volume mounted read-only.

Realtime broadcasting is optional and disabled by default. To run it, deploy the Reverb runtime, set `BROADCAST_CONNECTION=reverb`, configure `REVERB_APP_ID`, `REVERB_APP_KEY`, a dedicated `REVERB_APP_SECRET` of at least 32 bytes, set `REVERB_PUBLIC_URL` to the app origin, keep `REVERB_APP_RATE_LIMITING_ENABLED=true`, and set a bounded `REVERB_APP_MAX_CONNECTIONS`. In local Compose, the Reverb service is behind the `realtime` profile and binds to `127.0.0.1:${REVERB_PORT:-8080}`.

Before public release, run `make verify-reverb-origin` once in an environment with Docker and Node available. The target builds the local app image if needed, starts the Reverb runtime with production-shaped configuration, waits for the websocket port to accept connections, and performs two real websocket handshakes: the configured app origin must receive `101 Switching Protocols` plus `pusher:connection_established`, and a foreign origin must upgrade before receiving Pusher error `4009`.

After the environment is configured, a System Admin can enable realtime from installation settings. Enabling is refused when Reverb is incomplete. Realtime traffic stays on the app trust boundary only; artifact-host responses must not gain websocket credentials or outbound realtime access.

### Optional Cloudflare Turnstile authentication challenge

Turnstile is disabled by default. An operator enables it by supplying both
`TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` to **app-role replicas only**; there is no
database or admin-screen switch. Once both keys are present, the login, reset-link request, and
new-password pages automatically render the widget. The server verifies each challenge before
checking a password, sending a reset notification, or consuming a reset token. Do not provide the
secret to artifact-host, worker, or scheduler roles. Create the widget and keys in Cloudflare,
then follow Cloudflare's [server-side validation guidance](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/).

Set `TURNSTILE_EXPECTED_HOSTNAME` to the exact `APP_URL` host. Production boot and
`artifactflow:doctor` reject partial key configuration, Cloudflare's published test credentials,
a hostname that differs from `APP_URL`, credentials on a non-app runtime, or non-positive
timeouts. The server also requires the verification response to carry `success=true`, the
configured hostname, and the exact action for the submitted form: `login`,
`password_reset_request`, or `password_reset`. A challenge minted for one form therefore cannot be
reused on another. Any invalid response, timeout, connection failure, malformed JSON, or
non-success HTTP response fails closed with a generic verification error. The default two-second
connection and five-second request timeouts are capped in code at ten and fifteen seconds
respectively.

Outside production, supplying only one key or enabling Turnstile with a malformed hostname or
timeout returns a clear `503` on the affected authentication forms instead of an exception page.
Complete the pair and correct the settings, or remove both key values. Siteverify failures write one
bounded warning with a stable reason, the HTTP status when applicable, and only syntax-restricted
Cloudflare error codes. The warning never includes the challenge token, secret, visitor IP,
configured hostname, or response hostname; authentication route limits bound repeated failure
logging.

Enabling Turnstile adds a deliberate third-party data flow: the browser loads Cloudflare's
challenge script/frame, which Cloudflare says processes signals including the client IP, TLS
fingerprint, User-Agent, site key, and associated origin. The app additionally sends the challenge
token plus the request's derived client IP to Cloudflare's Siteverify endpoint. Review Cloudflare's
privacy and data-processing terms for your deployment, including the
[Turnstile Privacy Addendum](https://www.cloudflare.com/turnstile-privacy-policy/), and configure
`TRUSTED_PROXIES` correctly so the forwarded IP is authoritative. A
Cloudflare outage can prevent login and password recovery while the feature is enabled; remove
both keys and redeploy to return to the self-contained, rate-limit-only authentication path.

Turnstile is defense in depth against automated and distributed low-and-slow login attempts. The
existing five-attempt email+IP minute bucket, configurable source-IP minute bucket, and
IP-independent account-hour bucket remain the credential-stuffing backstop. Failed challenges
consume the email+IP and source-IP budgets but not the account-hour password-guess budget, because
no password was checked. Password-recovery submissions remain independently bounded by the
configured email/IP hourly reset limit, including challenge failures.

| Variable | Default | Purpose |
| --- | --- | --- |
| `TURNSTILE_SITE_KEY` | empty | Public widget key; paired with the secret to enable Turnstile |
| `TURNSTILE_SECRET_KEY` | empty | Server-side verification secret; app runtime only |
| `TURNSTILE_EXPECTED_HOSTNAME` | `APP_URL` host | Exact hostname required in successful verification responses |
| `TURNSTILE_CONNECT_TIMEOUT_SECONDS` | 2 | Siteverify connection timeout (hard-capped at 10 seconds) |
| `TURNSTILE_TIMEOUT_SECONDS` | 5 | Siteverify request timeout (hard-capped at 15 seconds) |

The isolated browser suite starts a second Laravel server inside the e2e app container with
Cloudflare's published always-pass credentials. It loads the real `/login`, `/forgot-password`, and
`/reset-password/{token}` responses, then verifies each page's emitted CSP, script nonce, configured
action, Cloudflare frame, and generated response token. The normal e2e server explicitly receives
blank Turnstile credentials, so existing authentication flows remain independent.
`make e2e` therefore needs outbound HTTPS access to `https://challenges.cloudflare.com`. Siteverify
and its action/hostname binding remain deterministic feature tests: Cloudflare's dummy validation
response hardcodes `action: test`, so accepting it in the application would weaken the form-action
contract being tested. Production boot rejects every published test credential.

Durable domain events that fail dispatch are quarantined so later events can continue. After fixing the listener or infrastructure fault, requeue one failed event by UID without exposing payload metadata:

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:requeue-domain-event 01H...'
```

The next scheduled or manual `artifactflow:dispatch-domain-events` run will replay it.

### Journal retention

The scheduler runs `artifactflow:prune-domain-events` nightly to delete dispatched journal rows whose `occurred_at` is older than `DOMAIN_EVENT_RETENTION_DAYS` (default 90). Undispatched and failed (quarantined) events are never pruned, so `artifactflow:requeue-domain-event` keeps working no matter how old the failure is. Audit entries are never pruned: `audit_entries.event_uid` is a soft reference into the journal by design (no foreign key), so user-facing audit history stays intact after the originating journal row is deleted. Run it manually with `--days=<n>` (minimum 7, so a typo cannot wipe a fresh journal) or preview with `--dry-run`:

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:prune-domain-events --dry-run'
```

The scheduler also runs `artifactflow:prune-credentials` nightly to reap dead credentials: trusted-device rows expired past `TRUSTED_DEVICE_RETENTION_DAYS` (default 0 — deleted as soon as they lapse) and MCP tokens that have been revoked or expired for longer than `MCP_TOKEN_RETENTION_DAYS` (default 30, so settings history keeps showing a recently revoked token before its row is removed). Neither can touch live access — an expired device cookie and a revoked or expired token are already rejected at authentication — so this only trims dead rows and shrinks the pool of token hashes at rest. Preview with `--dry-run`.

Production artifact preview also requires:

- `ARTIFACT_URL_SIGNING_KEY` set to a dedicated signing key, not `APP_KEY`.
- `ARTIFACT_FRAME_ANCESTORS` set to the trusted app origin.
- `ARTIFACT_PREVIEW_URL_TTL_SECONDS` kept short; defaults to 60 seconds, which is also the
  hard cap enforced for both saved URLs and draft capabilities.
- `ARTIFACT_MAX_BYTES` set to the maximum accepted single-file HTML size.

## Tunables

Every limit below ships with a safe default and can be overridden per install through the
environment. Values are read from `config/rate_limits.php`, `config/pages.php`, and
`config/external_sharing.php`.

System Admins enable external sharing and choose its maximum expiring-link
lifetime in the installation settings UI. The UI accepts whole days from 1
through 30 (7 by default); persistence and enforcement retain the equivalent
hour value internally so existing installations and expiry calculations remain
compatible. This maximum does not add an expiry to one-time links: those remain
usable only until their single redemption or explicit revocation.

Rate limits:

| Variable | Default | Limits |
| --- | --- | --- |
| `AUTHENTICATED_RATE_LIMIT_PER_MINUTE` | 120 | Authenticated web requests per user |
| `PAGE_WRITE_RATE_LIMIT_PER_MINUTE` | 30 | Page create/update/restore writes per user |
| `PAGE_PRESENCE_RATE_LIMIT_PER_MINUTE` | 120 | Presence heartbeats per user |
| `WORKSPACE_CREATES_PER_MINUTE` | 10 | Shared workspaces created per user |
| `WORKSPACE_INVITATIONS_PER_MINUTE` | 10 | Invitations sent per user |
| `WORKSPACE_INVITATION_ACCEPTS_PER_MINUTE` | 10 | Invitation accepts per user |
| `MARKDOWN_PREVIEW_RATE_LIMIT_PER_MINUTE` | 30 | Markdown preview renders per user |
| `DRAFT_PREVIEW_CAPABILITY_RATE_LIMIT_PER_MINUTE` | 30 | Authenticated draft capabilities issued per user |
| `ARTIFACT_PREVIEWS_PER_MINUTE` | 60 | Artifact preview loads per IP and per path |
| `MCP_PRE_AUTH_RATE_LIMIT_PER_MINUTE` | 300 | MCP requests per IP before authentication |
| `MCP_RATE_LIMIT_PER_MINUTE` | 60 | MCP requests per human or service-account principal across all of its tokens |
| `MCP_WRITE_RATE_LIMIT_PER_MINUTE` | 20 | MCP write tool calls per principal across all of its tokens |
| `ADMIN_STEP_UP_RATE_LIMIT_PER_MINUTE` | 5 | Password confirmations and MCP-token creation attempts per user; also the compatibility fallback for the renamed admin-2FA minute limit |
| `ADMIN_TWO_FACTOR_RATE_LIMIT_PER_MINUTE` | 5 | Administration 2FA confirmations per account per minute |
| `ADMIN_TWO_FACTOR_ACCOUNT_RATE_LIMIT_PER_HOUR` | 30 | Administration 2FA confirmations per account across source IPs per hour |
| `ADMIN_TWO_FACTOR_IP_RATE_LIMIT_PER_MINUTE` | 20 | Administration 2FA confirmations per source IP per minute |
| `LOGIN_IP_RATE_LIMIT_PER_MINUTE` | 20 | Login attempts per IP |
| `LOGIN_ACCOUNT_RATE_LIMIT_PER_HOUR` | 20 | Login attempts per account |
| `PASSWORD_RESETS_PER_HOUR` | 5 | Password reset requests per email+IP |
| `TWO_FACTOR_CHALLENGE_RATE_LIMIT_PER_MINUTE` | 5 | 2FA challenge attempts per session |
| `TWO_FACTOR_CHALLENGE_ACCOUNT_RATE_LIMIT_PER_HOUR` | 30 | 2FA challenge attempts per account |
| `TWO_FACTOR_CHALLENGE_IP_RATE_LIMIT_PER_MINUTE` | 20 | 2FA challenge attempts per IP |
| `TWO_FACTOR_MANAGEMENT_RATE_LIMIT_PER_MINUTE` | 5 | Post-authentication 2FA disable/recovery-code attempts per user |

Authentication freshness:

| Variable | Default | Purpose |
| --- | --- | --- |
| `TWO_FACTOR_ENROLLMENT_PASSWORD_TIMEOUT_SECONDS` | 180 | Time to start and finish initial 2FA enrollment using the just-validated login password |
| `AUTH_PASSWORD_TIMEOUT` | 900 | Freshness window after an explicit account password confirmation for other 2FA settings actions |
| `AUTH_ADMIN_TWO_FACTOR_TIMEOUT` | 900 | Freshness window after a live authenticator or recovery-code proof for Administration; falls back to the former `AUTH_ADMIN_PASSWORD_TIMEOUT` value on upgrade |

Content and storage limits:

| Variable | Default | Limits |
| --- | --- | --- |
| `PAGE_MARKDOWN_MAX_BYTES` | 5 MiB | Markdown source size per version |
| `PAGE_HTML_MAX_BYTES` | 5 MiB | HTML artifact size accepted on write |
| `PAGE_IMAGE_MAX_BYTES` | 5 MiB | PNG/JPEG upload byte ceiling; lowering it affects new uploads, not retained normalized versions |
| `PAGE_IMAGE_MAX_PIXELS` | 16 Mi pixels | New-upload decoded pixel ceiling; hard-capped at 16 Mi pixels while retained normalized versions remain readable up to 40 Mi pixels |
| `PAGE_IMAGE_MAX_DIMENSION` | 16,384 px | Maximum width or height |
| `IMAGE_PARSER_ENABLED` | `true` | Enables new image uploads. Set `false` to run without parser credentials; retained normalized images remain readable. |
| `IMAGE_PARSER_CONNECT_TIMEOUT_SECONDS` | 2 seconds | App-to-parser connection timeout (hard-capped at 10 seconds) |
| `IMAGE_PARSER_TIMEOUT_SECONDS` | 12 seconds | Whole normalization timeout (hard-capped at 30 seconds) |
| `IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS` | 120 seconds | Parser request timestamp tolerance (hard-capped at 300 seconds). Keep host clocks synchronized; authenticated skew failures are recorded as `image_parser.request_failed` with reason `clock_skew`. |
| `IMAGE_NORMALIZATION_USER_PIXEL_BUDGET_PER_MINUTE` | 64 Mi pixels | Per-principal decoded-pixel budget; must allow one maximum upload and cannot exceed 64 Mi pixels |
| `IMAGE_NORMALIZATION_INSTALLATION_PIXEL_BUDGET_PER_MINUTE` | 256 Mi pixels | Shared installation decoded-pixel budget; must be at least the principal budget and cannot exceed 256 Mi pixels |
| `IMAGE_NORMALIZATION_USER_WORK_BUDGET_PER_MINUTE` | 64 Mi work units | Per-principal non-pixel work budget. Input bytes count once, PNG ancillary/JPEG header metadata bytes count again, and every parsed chunk/marker costs 1 Ki work units. |
| `IMAGE_NORMALIZATION_INSTALLATION_WORK_BUDGET_PER_MINUTE` | 256 Mi work units | Installation-wide non-pixel work budget; must be at least the principal budget and cannot exceed 256 Mi work units. |
| `PDF_PROCESSOR_ENABLED` | `false` | Default-off gate for PDF web/MCP create, replace, restore, reprocess, native preview/download, and MCP PDF read/search. Authorized retained PDFs remain visible in the normal web catalog/search because this is a processing/delivery safety switch, not access revocation. The production boot gate currently rejects `true` until the PDF roadmap's containment and release gates are complete. Local/E2E app and artifact runtimes use the same boolean when testing, but the artifact host must not receive the processor URL or shared secret. |
| `PDF_PROCESSOR_URL` | `http://pdf-processor:8080` | App-runtime-only internal PDF processor origin. It must not be public or supplied to the artifact host. |
| `PDF_PROCESSOR_SHARED_SECRET` | empty | App-runtime-only HMAC secret shared with the PDF processor. Use at least 32 non-placeholder bytes and never reuse `APP_KEY` or the artifact signing key. |
| `PDF_PROCESSOR_CONNECT_TIMEOUT_SECONDS` | 2 seconds | App-to-processor connection timeout (hard-capped at 60 seconds). |
| `PDF_PROCESSOR_TIMEOUT_SECONDS` | 15 seconds | Whole app-to-processor request timeout (hard-capped at 60 seconds); the native engine has a shorter internal deadline. |
| `ARTIFACT_MAX_BYTES` | 10 MiB | Artifact size stored/served on read and signed normalized-image output budget (must be ≥ every Markdown/HTML/image upload limit in production; hard-capped at 64 MiB for image derivatives) |
| `ARTIFACT_DRAFT_PREVIEW_MAX_BODY` | 6 MB | Edge request-body cap for the capability-protected draft-preview route; keep above `PAGE_HTML_MAX_BYTES` for multipart overhead |
| `PAGE_WORKSPACE_MAX_STORAGE_BYTES` | 1 GiB | Total artifact storage per workspace |
| `PAGE_MAX_PAGE_STORAGE_BYTES` | 100 MiB | Total artifact storage per page |
| `PAGE_MAX_PAGE_VERSIONS` | 200 | Retained versions per page (retention cap: appends past it prune the oldest, never block the edit) |
| `PAGE_MAX_TAGS_PER_PAGE` | 25 | Tags per page |
| `EXTERNAL_SHARE_MAX_ACTIVE_PER_PAGE` | 20 | Hard active external-share ceiling per page; terminal shares do not count |
| `EXTERNAL_SHARE_MAX_ACTIVE_PER_INSTALLATION` | 10,000 | Hard active external-share ceiling across the installation |
| `EXTERNAL_SHARE_MAX_VIEW_SESSIONS_PER_SHARE` | 100 | Maximum concurrent window-lived viewer sessions retained for one expiring share; opening another evicts the oldest |
| `EXTERNAL_SHARE_CREATE_RATE_LIMIT_PER_MINUTE` | 10 | External-share creations per actor and page per minute |
| `EXTERNAL_SHARE_PUBLIC_RATE_LIMIT_PER_MINUTE` | 20 | Anonymous exchange/open attempts per source, selector, and operation per minute |
| `EXTERNAL_SHARE_PUBLIC_IP_RATE_LIMIT_PER_MINUTE` | 60 | Anonymous attempts per source across every selector and operation per minute |
| `WORKSPACE_INVITATION_TTL_DAYS` | 7 | Invitation validity |
| `WORKSPACE_RENAME_COOLDOWN_SECONDS` | 60 | Cooldown between workspace renames |

PNG normalization validates chunk CRCs, removes compressed text/profile metadata from the bytes
passed to GD, and bounds IDAT inflation to the exact IHDR-derived scanline envelope before native
decoding. Do not remove that preflight when changing image libraries: the pixel budgets assume
compressed input cannot expand outside the charged raster dimensions.
The parser health endpoint also performs a fixed one-pixel PNG decode/re-encode, so a healthy
status proves the configured PNG codec path rather than only the HTTP listener.

## Quality Gates

Local fast gate:

```sh
make quality
```

Full local pre-push gate:

```sh
make quality-full
```

`make e2e` is isolated from the normal local development app. It creates a temporary database on `db-test`, starts dedicated `e2e-app` and `e2e-artifact-host` services on `http://localhost:18180` and `http://127.0.0.1:18181` (different hosts on purpose, so app session cookies never reach the artifact origin), runs migrations, routes browser-test setup commands to the isolated app, and drops the temporary database when the run exits. The e2e containers are created with `--env-file docker/e2e.env` (a committed, comments-only interpolation guard), so values from your personal `.env` never leak into the e2e services — their configuration comes only from the compose-file defaults and the `E2E_*` variables the Makefile passes explicitly. Use `E2E_APP_PORT` and `E2E_ARTIFACT_HOST_PORT` if those ports are already occupied.

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
- Full Playwright E2E suite on Chromium, plus the tagged artifact security corpus—including the
  seeded artifact-parser differential fuzzer—on Firefox and WebKit.
- Production Caddy/FrankenPHP image build.
- Trivy image scan with vulnerability, secret, and misconfiguration scanners.
- Trivy filesystem scan combining repository secret and misconfiguration checks.

Nightly audit repeats dependency audits, production image build, and Trivy so new CVEs are surfaced even when no code has changed. Branch protection for protected release branches must require two status checks, or the gates are advisory rather than enforced: the aggregate `ci-required` check (which folds in the DCO sign-off gate) and the `cla` check from the separate `CLA` workflow. The CLA runs on `pull_request_target` and therefore cannot be a dependency of `ci-required`, so it must be required in branch protection in its own right — otherwise a pull request could merge without a signed CLA.

### Manual Safari and iOS security pass

Automated E2E runs the full suite on Chromium and the artifact security corpus on Firefox and
Playwright WebKit. Playwright WebKit is not released Safari and cannot reproduce every macOS/iOS
integration detail, so an occasional run in released Safari still matters. Before a
security-sensitive release, and after changing artifact CSP,
iframe sandbox flags, preview routing, fullscreen behavior, or browser-facing guard code—exercise
current macOS Safari plus a physical iPhone or iPad Safari. Use a test/staging deployment with real
TLS and genuinely distinct app/artifact hostnames; an iOS device cannot validate the production
origin boundary through the desktop-only `localhost`/`127.0.0.1` fixture.

Use non-sensitive test content and record the Safari/iOS versions and results:

1. Load both saved and draft malicious fixtures. Confirm the artifact request goes only to the
   artifact hostname, carries no app session cookie, and receives the complete header CSP,
   `no-store`, and `nosniff` headers.
2. Mutate one byte, newline style, Unicode normalization, and trailing whitespace after capability
   issuance. Each changed draft must receive the same not-found response; the exact original may
   replay only during its short TTL.
3. Attempt static and dynamic `iframe`/`frame`/`fencedframe`/`portal`, legacy
   `document.execCommand('insertHTML')`, `<object>`, `<embed>`, SVG `foreignObject`, worker, popup,
   download, form, and external-network paths. Include SVG/MathML `plaintext`, SVG `script`, and
   scripting-enabled HTML `noscript` parser breakouts inside both open and closed declarative
   shadow roots. Insert a benign `<link>` first and then mutate
   `rel`/`href` through properties, `setAttribute`, `setAttributeNS`, and `relList` to
   `dns-prefetch`, `preconnect`, `prefetch`, and `prerender`; repeat the ordering check with
   `<meta http-equiv="refresh">`. Also load static `rel="&#112reconnect"` and
   `http-equiv="&#114efresh"` payloads without trailing semicolons, and confirm the delivered
   response no longer contains their targets before checking that neither a TCP connection nor
   frame navigation occurs. Repeat the string arguments with stateful `toString()` objects
   that return a safe value during guard inspection and a dangerous value on a second coercion,
   including `document.execCommand` command names. The elements must be neutralized synchronously,
   the response must carry `X-DNS-Prefetch-Control: off`, and no nested browsing context,
   popup/download, form submission, worker, DNS lookup, or outbound connection should succeed.
4. Attempt `requestFullscreen()` and `requestPointerLock()` from artifact code. They must be denied
   or unavailable; on iOS, absence of pointer-lock support is expected. Then use ArtifactFlow's
   Fullscreen control and confirm it only CSS-maximizes the existing sandboxed iframe and exits
   cleanly without navigating or replacing the application document.
5. Open or paste a saved signed URL as a top-level document and attempt the equivalent draft POST.
   Modern Safari should receive the refusal notice rather than rendered artifact code. Also verify
   that a parent-page `<meta>` CSP cannot relax the artifact response's header policy.
6. Revoke access, archive the page, and move it between workspaces while a preview is open. Reloading
   the old URL must fail and renewal must require live access. Archive is a lifecycle state rather
   than access revocation, so a viewer who still has live page access may receive a new revision-bound
   URL; a revoked viewer may not. Already-rendered bytes remaining on screen are the documented
   non-revocable browser-delivery residual.
7. Open one-time and expiring external-share links both signed out and while an ArtifactFlow login
   session exists. Confirm the fragment is removed immediately and its secret never appears in a
   request URL, referrer, cookie, page markup, or artifact-preview URL. A one-time link must remain
   unconsumed at the confirmation screen, enter one window-lived viewer session only after the
   explicit open action, survive a reload in that window, and show the same unavailable state in a
   second window or browser.
8. Exercise external Markdown, HTML, and image shares. Confirm private wiki links are inert text,
   HTML remains in an opaque `sandbox="allow-scripts"` artifact-origin frame, and images remain in
   a scriptless `sandbox=""` frame. Revoke the share, disable installation-wide external sharing,
   archive the page, and move or access-invalidate it; each subsequent viewer reload and preview-URL
   renewal must fail without disclosing the reason.

Any divergence is a release blocker until it is reproduced, added to the automated corpus where
possible, and reflected in `THREAT-MODEL.md`.

## Verifying Release Images

Every `v*` tag runs the `Release` workflow, which builds the production image, gates it on the Trivy scan, pushes it to `ghcr.io/gadsotek/artifactflow`, and publishes a GitHub Release whose notes carry the immutable image digest. Always deploy by digest, not by tag. The `:latest` tag is moved only for a final `vMAJOR.MINOR.PATCH` release; a pre-release tag (for example `v1.2.0-rc1`) publishes its exact version tag but never becomes `:latest`, so pulling `:latest` cannot land on an unfinished build.

Each published image carries two keyless-signed (Sigstore) attestations bound to its digest and pushed alongside it in the registry: SLSA build provenance (proving it was built by this repository's Release workflow) and a CycloneDX SBOM. The SBOM is also attached to the release as `sbom.cdx.json`. Verify both before running the image, using the digest from the release notes:

```sh
gh attestation verify \
  oci://ghcr.io/gadsotek/artifactflow@sha256:<digest> \
  --repo Gadsotek/artifactflow
```

A successful verification confirms the image was produced by this repository's release pipeline and was not tampered with after signing. If verification fails, do not deploy the image.

## Backup & Restore

ArtifactFlow has two stateful data stores that must be captured together:

- PostgreSQL stores users, workspaces, page metadata, page-version rows, provenance ingests/assertions/external references, permissions, audit entries, queues, and durable domain events.
- The private artifacts disk stores untrusted Markdown and single-file HTML bytes, normalized PNG/JPEG derivatives, and retained PDF originals referenced by `page_versions.content_storage_path`. Original image uploads are not retained.

Backups must also be paired with secret-manager custody for `APP_KEY`, `ARTIFACT_URL_SIGNING_KEY`, `IMAGE_PARSER_SHARED_SECRET`, and—when the PDF milestone is enabled—`PDF_PROCESSOR_SHARED_SECRET`. Those keys are not included in data backups and must not be copied into backup manifests. Losing `APP_KEY` makes encrypted application data, TOTP secrets, sessions, and trusted-device cookies unrecoverable. Rotating or losing `ARTIFACT_URL_SIGNING_KEY` invalidates outstanding signed artifact-preview URLs, which is acceptable for short-lived previews but must be expected during restore. Parser/processor secrets protect no data at rest; rotate each one on the app and its matching service together or the corresponding writes fail closed until they match.

Run a local Compose backup with:

```sh
make backup
```

The script writes `backups/<timestamp>/postgres.dump`, `backups/<timestamp>/artifacts.tar.gz`, and `backups/<timestamp>/manifest.json`. The manifest contains a format version and SHA-256 digest for both payloads; keep all three files together. It creates the database dump first, then snapshots the private artifacts disk. This database dump first ordering is load-bearing for *new* writes: the artifact bytes are stored before the `page_versions` row is committed (see `PageVersionWriter`), so a version created during the backup window already has its file by the time the disk snapshot runs, and the worst case is an extra orphan file rather than a restored row pointing at a missing file.

The ordering does not, however, cover *deletions*. A version that is pruned to the retention cap or hard-deleted removes its artifact file only after the row is deleted and the surrounding transaction commits. If that row is still present in the database dump but the delete commits and removes the file before the disk snapshot runs, the restored copy can hold a row pointing at a missing file (`verify-artifacts` reports it as `missing-file`). This is a benign, bounded inconsistency for a hot backup — the referenced version is one the deployment was already discarding — but it means a hot backup is not point-in-time consistent across both stores. For strict consistency, place the app in maintenance/read-only mode or use a coordinated volume snapshot so no version is deleted between the dump and the snapshot.

Preview the backup actions without writing files:

```sh
make backup BACKUP_ARGS='--dry-run'
```

Restore from a backup directory with:

```sh
make restore RESTORE_ARGS='backups/20260629T120000Z'
```

Before restore, stop the `app`, `artifact-host`, `worker`, and `scheduler` roles while leaving PostgreSQL available. The restore script fails closed if any application role is running, paused, or restarting, verifies both payload hashes against `manifest.json`, and refuses to restore over a non-empty database or non-empty artifacts root unless `--force` is supplied and the operator types `RESTORE`. It uses a non-serving one-shot app container to access the artifacts volume, runs `pg_restore --clean --if-exists` for PostgreSQL, and extracts artifacts back into the configured private artifact root. For an exact disaster recovery drill, restore into empty volumes; extracting into an existing artifacts root can leave unrelated orphan files behind. Never serve extracted artifact files from the trusted app origin or open them directly in a browser during recovery.

Backups created by ArtifactFlow before manifests included `format_version` and payload hashes
remain recoverable through an explicit upgrade. First verify the backup directory's provenance and
that its `postgres.dump`, `artifacts.tar.gz`, and `manifest.json` have remained together, then run:

```sh
make restore RESTORE_ARGS='--upgrade-legacy-manifest backups/20260629T120000Z'
```

The flag accepts only the recognizable legacy ArtifactFlow manifest shape, prints a warning that
the old format cannot prove historical payload pairing, atomically adds hashes for both current
payload files, and then proceeds through the normal hash verification and restore checks. It does
not make an arbitrary missing or partial manifest trusted. The upgrade changes `manifest.json`, so
retain an immutable copy of the original backup set when recovery policy requires one.

After every restore, run:

```sh
make backup-verify
```

`make backup-verify` runs `artifactflow:verify-artifacts --sample=25` through the app container. Use `make run-app-cmd APP_CMD='php artisan artifactflow:verify-artifacts --all'` for a full check. The command reads `page_versions.content_storage_path` from PostgreSQL, reads bytes through the private artifacts disk, and reports only aggregate counts for checked, ok, missing-file, and hash-mismatch rows. It must not print private artifact content, signed URLs, database passwords, `APP_KEY`, or `ARTIFACT_URL_SIGNING_KEY`.

Also run `artifactflow:diagnose-2fa` after restore drills. It verifies encrypted 2FA secret readability and reports only aggregate counts so operators can decide whether users should rely on recovery codes or console break-glass.

Retention should match the deployment's recovery objective. A practical self-hosted default is daily encrypted backups with at least 14 restore points, stored away from the application host and access-restricted like production artifact storage. Test a restore regularly, record the backup timestamp and verification counts, and rotate storage credentials separately from application signing keys.

Version-content pruning intentionally retains provenance ingests, assertions, and external
references in PostgreSQL. Page hard deletion removes them. The first provenance slice has no
automatic external-reference expiry/redaction job, so operators must treat database backups as
containing those sensitive references for the full backup-retention period. A future audited
redaction cannot erase older immutable backup copies; recovery and legal-retention policy must
account for that.
