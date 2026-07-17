# ArtifactFlow Operations

Last updated: 2026-07-02

## Local Runtime

The local stack follows the architecture document:

| Service | Role |
| --- | --- |
| `app` | Main Laravel HTTP origin. |
| `artifact-host` | Same code image, separate stateless artifact-serving origin. |
| `worker` | Queue worker (`queue:work`). Scans, projections, and audit side effects run synchronously inside the write transaction; the only queued work today is outbound mail. |
| `scheduler` | Laravel scheduler loop (`schedule:work`): outbox dispatch and the nightly retention jobs. |
| `db` | PostgreSQL 17 for app data, queues, search, and event outbox. |
| `edge` | Optional local Caddy reverse proxy for named host routing. |

Start the core stack:

```sh
make up
```

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

Registration is disabled by default. Create a verified login user from the app container. Read the password into your shell (hidden) and forward it into the container **by name**, so the secret never lands in a process listing or shell history:

```sh
read -rs -p 'New user password: ' ARTIFACTFLOW_CREATE_USER_PASSWORD; echo
export ARTIFACTFLOW_CREATE_USER_PASSWORD
docker compose exec -T -e ARTIFACTFLOW_CREATE_USER_PASSWORD app \
  php artisan artifactflow:create-user \
  --name="Admin User" \
  --email="admin@example.test"
unset ARTIFACTFLOW_CREATE_USER_PASSWORD
```

> Avoid `--password="..."` and `-e VAR="value"` with an inline value: both place the secret in the `docker compose exec` argv, where it is visible to other users via `ps`/`/proc` and is written to your shell history. The `-e VARNAME` form above passes only the variable name; Docker reads the value from your environment.

The password must be at least 12 characters. The command creates a normal verified user, provisions their personal workspace, and records audit/domain events.

Reset a user's password from the app container when an operator recovery path is needed. Use the same by-name forwarding so the reset password stays out of the argv and shell history:

```sh
read -rs -p 'Reset password: ' ARTIFACTFLOW_RESET_PASSWORD; echo
export ARTIFACTFLOW_RESET_PASSWORD
docker compose exec -T -e ARTIFACTFLOW_RESET_PASSWORD app \
  php artisan artifactflow:reset-password \
  --email="admin@example.test"
unset ARTIFACTFLOW_RESET_PASSWORD
```

The command rotates the user's password and remember token, invalidates database-backed sessions for that user, and records audit/domain events without storing or printing the password.

Create or promote the deployment system admin when needed. Forward the password by name as above (setting `ARTIFACTFLOW_ADMIN_PASSWORD` persistently is rejected by the production boot gate, so provide it only for this one-shot command):

```sh
read -rs -p 'System admin password: ' ARTIFACTFLOW_ADMIN_PASSWORD; echo
export ARTIFACTFLOW_ADMIN_PASSWORD
docker compose exec -T -e ARTIFACTFLOW_ADMIN_PASSWORD app \
  php artisan artifactflow:bootstrap-admin \
  --name="Admin User" \
  --email="admin@example.test"
unset ARTIFACTFLOW_ADMIN_PASSWORD
```

Fresh installs require System Admins to enroll TOTP 2FA by default. A System Admin can require 2FA for all users from the installation settings screen. If an operator loses the only admin's second factor, use the console-only break-glass path:

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

MCP is served only by the app runtime at `POST /mcp`. The artifact-host runtime must return not found for the same path. MCP clients authenticate with bearer tokens issued either from a human user's account settings or from the service-account CLI path. Every tool call still uses the normal workspace/page policies, scanners, optimistic concurrency checks, and audit/domain-event trail.

Human users create their own MCP tokens from Security -> MCP tokens. Creation requires the account to have TOTP two-factor authentication enabled, then requires the current password and a fresh authenticator code in the create request. The plaintext token is shown once. Token list and revoke are scoped to the signed-in user's own account; revocation does not require the strong create step-up so rotation stays cheap. Workspace scope is an explicit choice: select one or more workspaces to bind the token to that smaller read/write ceiling, or check "All workspaces" to grant every workspace the account can reach now and any it joins in future. An empty selection with "All workspaces" unchecked is rejected, never silently minted as an all-workspaces token.

Per-user token reach follows the user's live workspace memberships and the token's optional workspace scope. A workspace-scoped token cannot discover workspaces or taxonomy, search, read, create, update, or revert anything outside that scope, even if the principal has broader browser access. System Admin is installation/account authority only and never grants workspace or page content access. MCP further de-elevates workspace Admin to Editor, caps Admin page grants to Editor, and removes page-admin capabilities such as manage access, archive, hard delete, change access mode, and transfer ownership.

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
- `mcp:read` reads an in-scope page as an explicit untrusted data envelope. The server never treats read content as authorization for a later write.
- `mcp:create` creates Markdown or single-file HTML pages through the normal page creation handler. It can attach tag names and either select a category by UID or create a workspace-local category by name in the same operation. The same scope powers `create_category` and `create_tag`; both require live Editor authority in the supplied in-scope workspace, and standalone tag creation remains installation-wide after that authority check.
- `mcp:update` appends a new page version through the normal update handler, requires a fresh `base_version_uid`, and also powers one-action revert to the previous version.

Content scanning remains advisory except for explicit secret and credential patterns, which block writes. Inline script in an HTML artifact is expected; it is recorded as a warning finding and audit trail, not held for human acknowledgement. Descriptions are scanned for obvious secrets and prompt-injection role markers before save. MCP taxonomy names and slugs are user-authored data and are therefore returned inside the same explicit untrusted-data envelope as other user-authored text.

Set `MCP_PRE_AUTH_RATE_LIMIT_PER_MINUTE` to tune the pre-authenticated source-IP ceiling, `MCP_RATE_LIMIT_PER_MINUTE` to tune the authenticated token ceiling, and `MCP_WRITE_RATE_LIMIT_PER_MINUTE` to tune per-token create/update/revert write throughput. Invalid or unauthenticated bearer attempts are bucketed by source IP before token lookup so random bearer rotation cannot create fresh unauthenticated buckets. Authenticated calls are also limited after token authentication. If many legitimate MCP clients share one NAT or proxy egress IP, size the pre-auth limit for the aggregate caller pool or route trusted clients through distinct egress identities. MCP callers may pass `Mcp-Agent-Session` to add a non-secret agent-session identifier to MCP-created version and restore audit metadata. Never place signed preview URLs, application session cookies, or raw authorization headers in MCP client prompts or logs.

## Mail Delivery

Workspace invitations, password reset links, and other outbound mail default to Laravel's local `log` transport, so a fresh **local** install boots without a third-party mail account (mail is written to the log, not delivered). For real delivery, choose a transport explicitly: set `MAIL_MAILER=resend` with `RESEND_KEY` from your Resend account, or `MAIL_MAILER=smtp` with your SMTP settings, and use a verified sender in `MAIL_FROM_ADDRESS`.

In **production** the `log` and `array` transports are not permitted: they silently discard invitation and password-reset emails, so the boot gate rejects them and the container will not start until `MAIL_MAILER` names a deliverable transport. A deliverable mail transport is therefore a first-boot requirement in production, not an optional add-on, and outbound mail depends on the `worker` role (`queue:work`) actually running — see [Production Runtime](#production-runtime).

Invitation creation is rate-limited with `WORKSPACE_INVITATIONS_PER_MINUTE`; invitation acceptance has its own `WORKSPACE_INVITATION_ACCEPTS_PER_MINUTE` budget because it accepts a UID-bearing link. Invitation revoke/delete remains on the authenticated route budget plus workspace-admin authorization.

## Production Runtime

ArtifactFlow supports production self-hosting through its production image and runtime contract. The repository does not ship a one-click production Compose stack: operators provide deployment-specific orchestration, TLS termination, PostgreSQL, secrets, and persistent volumes. The bundled `docker-compose.yml` remains local-only.

The production image uses Caddy plus FrankenPHP:

```sh
make build-prod
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

The `worker` runs `queue:work` (outbound mail is the only queued work today) and the
`scheduler` runs `schedule:work`, which drives `artifactflow:dispatch-domain-events` (the
outbox relay) and the nightly `prune-domain-events` / `prune-credentials` jobs. Skipping
the scheduler halts outbox dispatch and journal/credential retention (records accumulate
undispatched rather than being lost); skipping the worker leaves invitation and
password-reset emails queued but never delivered. The local `docker-compose.yml` `worker`
and `scheduler` services show the exact `command:` override to mirror. See
[Mail Delivery](#mail-delivery) for why the worker is required in practice.

Production must use separate origins for the app and artifact host. Do not serve uploaded artifact HTML from the main app origin.

### First boot and configuration

The production image bakes `APP_ENV=production`, and the entrypoint runs `php artisan
config:cache` — which boots the fail-closed production security gate — before it serves any
request. Production configuration is supplied as **environment variables** (from your
orchestrator or secret manager), not by editing a `.env` file inside the immutable image.
Every value the gate requires (both artifact HTTPS origins, a dedicated
`ARTIFACT_URL_SIGNING_KEY`, `APP_KEY`, `DB_SSLMODE=verify-full` + `DB_SSLROOTCERT`, a
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
(kept distinct from `APP_KEY`) and store it in your secret manager.

PostgreSQL transport must verify the server identity in production. Set `DB_SSLMODE=verify-full` and mount a trusted CA bundle or database CA, then point `DB_SSLROOTCERT` at that file. The production boot guard rejects `disable`, `allow`, `prefer`, `require`, and `verify-ca` because those modes either permit cleartext fallback or skip hostname verification.

If you set `SESSION_DOMAIN`, it must not cover the artifact host. A broad parent domain such as `.example.internal` can send app cookies to `artifacts.example.internal`; use a host-only app session cookie or an app-only domain instead.

HTTP Strict Transport Security is sent on every response with a two-year `max-age` (tune with `HSTS_MAX_AGE`). The `includeSubDomains` and `preload` directives are **opt-in** because both reach past the app host and are hard to undo — a preload submission is a near-permanent commitment that forces HTTPS on every sibling subdomain, including the artifact host. Enable them only once every subdomain is HTTPS-only: set `HSTS_INCLUDE_SUBDOMAINS=true` and `HSTS_PRELOAD=true` for the app's (PHP) responses, and mirror the same value into `CADDY_HSTS` (for example `max-age=63072000; includeSubDomains; preload`) so the Caddy fallback used for static files matches. Left unset, both default to a safe host-scoped policy.

`TRUSTED_PROXIES` must name the real TLS edge so the app derives the client IP from `X-Forwarded-For` rather than the proxy's own address. Set it to the edge's address(es) or CIDR; the boot gate rejects an empty value, `*`, and address-space-wide ranges (`0.0.0.0/0`, the default Docker `172.16.0.0/12`). The special value `REMOTE_ADDR` trusts whatever connects directly — the immediate peer — as the proxy, which is safe **only** when the app port is reachable exclusively through the edge. If the app is directly reachable by untrusted clients under `REMOTE_ADDR`, any of them can forge `X-Forwarded-For` and defeat the IP-keyed rate limiters and audit trail; `php artisan artifactflow:doctor` emits a warning whenever production trusts `REMOTE_ADDR` so that network-isolation assumption is a deliberate choice.

Realtime broadcasting is optional and disabled by default. To run it, deploy the Reverb runtime, set `BROADCAST_CONNECTION=reverb`, configure `REVERB_APP_ID`, `REVERB_APP_KEY`, a dedicated `REVERB_APP_SECRET` of at least 32 bytes, set `REVERB_PUBLIC_URL` to the app origin, keep `REVERB_APP_RATE_LIMITING_ENABLED=true`, and set a bounded `REVERB_APP_MAX_CONNECTIONS`. In local Compose, the Reverb service is behind the `realtime` profile and binds to `127.0.0.1:${REVERB_PORT:-8080}`.

Before public release, run `make verify-reverb-origin` once in an environment with Docker and Node available. The target builds the local app image if needed, starts the Reverb runtime with production-shaped configuration, waits for the websocket port to accept connections, and performs two real websocket handshakes: the configured app origin must receive `101 Switching Protocols` plus `pusher:connection_established`, and a foreign origin must upgrade before receiving Pusher error `4009`.

After the environment is configured, a System Admin can enable realtime from installation settings. Enabling is refused when Reverb is incomplete. Realtime traffic stays on the app trust boundary only; artifact-host responses must not gain websocket credentials or outbound realtime access.

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
environment. Values are read from `config/rate_limits.php` and `config/pages.php`.

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
| `MCP_RATE_LIMIT_PER_MINUTE` | 60 | MCP requests per token |
| `MCP_WRITE_RATE_LIMIT_PER_MINUTE` | 20 | MCP write tool calls per token |
| `ADMIN_STEP_UP_RATE_LIMIT_PER_MINUTE` | 5 | Step-up confirmations per user |
| `LOGIN_IP_RATE_LIMIT_PER_MINUTE` | 20 | Login attempts per IP |
| `LOGIN_ACCOUNT_RATE_LIMIT_PER_HOUR` | 20 | Login attempts per account |
| `PASSWORD_RESETS_PER_HOUR` | 5 | Password reset requests per email+IP |
| `TWO_FACTOR_CHALLENGE_RATE_LIMIT_PER_MINUTE` | 5 | 2FA challenge attempts per session |
| `TWO_FACTOR_CHALLENGE_ACCOUNT_RATE_LIMIT_PER_HOUR` | 30 | 2FA challenge attempts per account |
| `TWO_FACTOR_CHALLENGE_IP_RATE_LIMIT_PER_MINUTE` | 20 | 2FA challenge attempts per IP |

Content and storage limits:

| Variable | Default | Limits |
| --- | --- | --- |
| `PAGE_MARKDOWN_MAX_BYTES` | 5 MiB | Markdown source size per version |
| `PAGE_HTML_MAX_BYTES` | 5 MiB | HTML artifact size accepted on write |
| `ARTIFACT_MAX_BYTES` | 10 MiB | HTML artifact size served on read (must be ≥ the write limit) |
| `ARTIFACT_DRAFT_PREVIEW_MAX_BODY` | 6 MB | Edge request-body cap for the capability-protected draft-preview route; keep above `PAGE_HTML_MAX_BYTES` for multipart overhead |
| `PAGE_WORKSPACE_MAX_STORAGE_BYTES` | 1 GiB | Total artifact storage per workspace |
| `PAGE_MAX_PAGE_STORAGE_BYTES` | 100 MiB | Total artifact storage per page |
| `PAGE_MAX_PAGE_VERSIONS` | 200 | Retained versions per page (retention cap: appends past it prune the oldest, never block the edit) |
| `PAGE_MAX_TAGS_PER_PAGE` | 25 | Tags per page |
| `WORKSPACE_INVITATION_TTL_DAYS` | 7 | Invitation validity |
| `WORKSPACE_RENAME_COOLDOWN_SECONDS` | 60 | Cooldown between workspace renames |

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
- Playwright E2E suite on Chromium (cross-engine Firefox and WebKit coverage is planned; tracked separately).
- Production Caddy/FrankenPHP image build.
- Trivy image scan with vulnerability, secret, and misconfiguration scanners.
- Trivy filesystem scan combining repository secret and misconfiguration checks.

Nightly audit repeats dependency audits, production image build, and Trivy so new CVEs are surfaced even when no code has changed. Branch protection for protected release branches must require two status checks, or the gates are advisory rather than enforced: the aggregate `ci-required` check (which folds in the DCO sign-off gate) and the `cla` check from the separate `CLA` workflow. The CLA runs on `pull_request_target` and therefore cannot be a dependency of `ci-required`, so it must be required in branch protection in its own right — otherwise a pull request could merge without a signed CLA.

### Manual Safari and iOS security pass

Automated e2e currently runs on Chromium only (cross-engine Firefox and WebKit coverage is
planned but deferred), so an occasional run in released Safari matters more, not less. Before a
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
3. Attempt static and dynamic `iframe`/`frame`/`fencedframe`/`portal`, `<object>`, `<embed>`, SVG
   `foreignObject`, worker, popup, download, form, and external-network paths. No nested browsing
   context, popup/download, form submission, worker, or outbound connection should succeed.
4. Attempt `requestFullscreen()` and `requestPointerLock()` from artifact code. They must be denied
   or unavailable; on iOS, absence of pointer-lock support is expected. Then use ArtifactFlow's
   Fullscreen control and confirm it only CSS-maximizes the existing sandboxed iframe and exits
   cleanly without navigating or replacing the application document.
5. Open or paste a saved signed URL as a top-level document and attempt the equivalent draft POST.
   Modern Safari should receive the refusal notice rather than rendered artifact code. Also verify
   that a parent-page `<meta>` CSP cannot relax the artifact response's header policy.
6. Revoke access, archive the page, and move it between workspaces while a preview is open. Reloading
   the old URL must fail and renewal must require live access; already-rendered bytes remaining on
   screen are the documented non-revocable browser-delivery residual.

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

- PostgreSQL stores users, workspaces, page metadata, page-version rows, permissions, audit entries, queues, and durable domain events.
- The private artifacts disk stores the untrusted Markdown and single-file HTML bytes referenced by `page_versions.content_storage_path`.

Backups must also be paired with secret-manager custody for `APP_KEY` and `ARTIFACT_URL_SIGNING_KEY`. Those keys are not included in data backups and must not be copied into backup manifests. Losing `APP_KEY` makes encrypted application data, TOTP secrets, sessions, and trusted-device cookies unrecoverable. Rotating or losing `ARTIFACT_URL_SIGNING_KEY` invalidates outstanding signed artifact-preview URLs, which is acceptable for short-lived previews but must be expected during restore.

Run a local Compose backup with:

```sh
make backup
```

The script writes `backups/<timestamp>/postgres.dump`, `backups/<timestamp>/artifacts.tar.gz`, and `backups/<timestamp>/manifest.json`. It creates the database dump first, then snapshots the private artifacts disk. This database dump first ordering is load-bearing for *new* writes: the artifact bytes are stored before the `page_versions` row is committed (see `PageVersionWriter`), so a version created during the backup window already has its file by the time the disk snapshot runs, and the worst case is an extra orphan file rather than a restored row pointing at a missing file.

The ordering does not, however, cover *deletions*. A version that is pruned to the retention cap or hard-deleted removes its artifact file only after the row is deleted and the surrounding transaction commits. If that row is still present in the database dump but the delete commits and removes the file before the disk snapshot runs, the restored copy can hold a row pointing at a missing file (`verify-artifacts` reports it as `missing-file`). This is a benign, bounded inconsistency for a hot backup — the referenced version is one the deployment was already discarding — but it means a hot backup is not point-in-time consistent across both stores. For strict consistency, place the app in maintenance/read-only mode or use a coordinated volume snapshot so no version is deleted between the dump and the snapshot.

Preview the backup actions without writing files:

```sh
make backup BACKUP_ARGS='--dry-run'
```

Restore from a backup directory with:

```sh
make restore RESTORE_ARGS='backups/20260629T120000Z'
```

The restore script refuses to restore over a non-empty database or non-empty artifacts root unless `--force` is supplied and the operator types `RESTORE`. It uses `pg_restore --clean --if-exists` for PostgreSQL and extracts artifacts back into the configured private artifact root. For an exact disaster recovery drill, restore into empty volumes; extracting into an existing artifacts root can leave unrelated orphan files behind. Never serve extracted artifact files from the trusted app origin or open them directly in a browser during recovery.

After every restore, run:

```sh
make backup-verify
```

`make backup-verify` runs `artifactflow:verify-artifacts --sample=25` through the app container. Use `make run-app-cmd APP_CMD='php artisan artifactflow:verify-artifacts --all'` for a full check. The command reads `page_versions.content_storage_path` from PostgreSQL, reads bytes through the private artifacts disk, and reports only aggregate counts for checked, ok, missing-file, and hash-mismatch rows. It must not print private artifact content, signed URLs, database passwords, `APP_KEY`, or `ARTIFACT_URL_SIGNING_KEY`.

Also run `artifactflow:diagnose-2fa` after restore drills. It verifies encrypted 2FA secret readability and reports only aggregate counts so operators can decide whether users should rely on recovery codes or console break-glass.

Retention should match the deployment's recovery objective. A practical self-hosted default is daily encrypted backups with at least 14 restore points, stored away from the application host and access-restricted like production artifact storage. Test a restore regularly, record the backup timestamp and verification counts, and rotate storage credentials separately from application signing keys.
