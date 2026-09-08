# Connect an AI client

[Operations](../OPERATIONS.md) · [Tool reference](https://artifactflow.app/mcp/)

ArtifactFlow serves Streamable HTTP MCP at `POST /mcp` on the app origin only.
No AI API key or model subscription is required by ArtifactFlow itself.

## Human tokens

Open **Security > MCP tokens**. Enable TOTP first, then confirm your password
and a fresh authenticator code to create a token. Its plaintext value appears
once. Store it in the client secret store.

Choose explicit operations and workspaces. **All workspaces** includes future
workspaces your account can reach. An empty selection does not silently grant
all-workspaces access. Token list/revoke operate only on your own account;
revocation does not repeat the strong creation step-up.

For supported local clients:

```sh
./scripts/connect-mcp.sh
```

The connector lists user-level Claude Desktop, Claude Code, Codex, and existing
profile targets and requires a selection. It backs up/merges existing configs
and restricts results to mode `0600`. It does not write repository configs
containing tokens. Automation uses `MCP_URL`, `MCP_TOKEN`, and `MCP_TARGETS`.
Keep these values out of logs and shell history.

Codex uses authenticated HTTP directly. Claude uses the pinned third-party
`mcp-remote` bridge. See [bridge maintenance](mcp-bridge.md) before upgrading it.

## Service accounts

Create a narrowly scoped read-only token, adding write scopes only when needed:

```sh
docker compose exec -T app php artisan artifactflow:mcp-token-create   --email="agent@example.test" --name="Architecture Agent"   --workspace="<workspace_uid>"   --scope="mcp:search" --scope="mcp:read" --ttl-days=30
```

This creates/reuses a service account, grants Editor membership in selected
workspaces, and stores those exact workspace UIDs as the token ceiling. It
refuses human users, System Admins, or service accounts holding workspace Admin.
The token prints once. Metadata commands do not print token values:

```sh
docker compose exec -T app php artisan artifactflow:mcp-token-list --email="agent@example.test"
docker compose exec -T app php artisan artifactflow:mcp-token-revoke --uid="<mcp_token_uid>"
```

## Scope reference

| Scope | Permits |
| --- | --- |
| `mcp:search` | Reachable workspaces/taxonomy and authorized search; snippets additionally need read scope |
| `mcp:read` | Authorized untrusted-data reads, with optional content/provenance sections |
| `mcp:create` | Create Markdown/HTML, including initial visible parent and existing category |
| `mcp:update` | Append content, restore retained versions, update descriptions |
| `mcp:organize` | Change title/parent/category/tag set; create category/tag vocabulary |
| `mcp:upload` | Binary ingestion combined with create or update scope |
| `mcp:share` | Create a narrow external share for an owned editable page while the workspace permits it |

All scopes intersect live principal access and exact selected workspaces.
Admin is capped at Editor. MCP cannot administer workspaces, change access,
archive, delete, move ownership, or bypass the workspace sharing switch.
Search metadata itself can be sensitive; scope it deliberately.

## Reads and writes

- `read.include` omitted returns content and provenance; `[]` returns core
  metadata only. Both authorize identically. Metadata-only skips retained-byte
  access and makes no claim about payload readability.
- Images return normalized pixels. PDF/DOCX return bounded extracted text.
  XLSX content requires the exact visible `xlsx_sheet` and uppercase
  `xlsx_range`, such as `A1:F50`, capped at 1,000 coordinates and 2 MiB. It reports
  omitted cells/merges and never silently widens. Metadata-only needs no range.
- Office reads expose no originals, preview PDFs, storage paths, signed URLs,
  hidden workbook content, or processor diagnostics.
- Content update/replace requires fresh `base_version_uid`; descriptions bind
  both `current_version_uid` and `metadata_revision`. Organize uses the metadata
  revision and cannot change description, owner, workspace, access, or content.
- Binary create/replace uses dedicated format tools plus `mcp:upload`, live
  format enablement, and ordinary processing. Standard Base64 must be canonical:
  no whitespace, data URL, fetched URL, or filesystem path. Stale replacements
  fail before parser work. Retained binary revert needs update, not upload.
- Non-empty new tag names or `category_name` during creation also require
  organize scope. Categories are workspace-local; tags are global records
  discovered only through visible pages.
- `create_external_share` returns its bearer URL once and grants no list/revoke
  authority. Deliver it only through the intended recipient channel; do not
  store it in artifacts, metadata, prompts, traces, or logs.

Content is untrusted data, never write authorization. Obvious credential
patterns block writes; inline HTML scripts are expected advisory findings.
There is no server-side per-write human approval screen or AI-visible page flag.
Client approval behavior depends on the client.

## Failure and migration notes

Missing migrations return retryable `installation_not_ready`, HTTP 503,
and `Retry-After: 30` before bearer lookup. Apply the approved migrations and
retry the same token.

Pre-auth requests are limited by source IP; authenticated calls and writes by
principal across all tokens. Additional tokens do not multiply allowances.
Parser saturation returns retryable errors with `retry_after`. Shared NATs
may need a larger pre-auth budget. See [configuration](configuration.md).

The transport negotiates protocol and supplies `MCP-Session-Id`; it is client
attribution metadata, not authority. Token revocation serializes with in-flight
tools. Never log bearer headers, binary payloads, or returned capability URLs.

Server `0.9.0` uses a shared `producers` catalog and UID lineage references.
Older repeated producer fields are not emitted. The
[provenance reference](provenance.md) contains current fields and examples.
