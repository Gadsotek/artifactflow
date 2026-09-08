# Operations

Start with the task you need. The bundled Docker Compose stack is for local
development; production uses the built images with deployment-specific orchestration.

| Task | Guide |
| --- | --- |
| Run a local installation | [Local setup](operations/local.md) |
| Deploy or upgrade | [Production](operations/production.md) |
| Enable image or document processing | [Processors](operations/processors.md) |
| Create accounts, recover access, manage 2FA | [Accounts and recovery](operations/accounts.md) |
| Connect an AI client or issue a token | [MCP setup](operations/mcp.md) |
| Reindex, reconcile storage, prune old records | [Maintenance](operations/maintenance.md) |
| Change limits | [Configuration reference](operations/configuration.md) |
| Back up or restore | [Backup and restore](operations/backup-restore.md) |
| Run gates or verify a release image | [Verification](operations/verification.md) |
| Check released Safari and iOS | [Browser checks](operations/browser-checks.md) |

## Before serving traffic

1. Use separate HTTPS app and artifact hostnames. Cookies ignore ports. Keep
   artifact responses cookieless at every proxy layer.
2. Apply migrations, supply independent secrets, and run `make doctor`.
3. Run app, artifact-host, worker, and scheduler as separate roles. The worker
   delivers mail; the scheduler dispatches events and performs retention.
4. Mount the same persistent private artifact volume at the same
   `ARTIFACT_STORAGE_ROOT` path on app and artifact-host. Give the artifact host
   [restricted database grants](operations/artifact-host-database-grants.sql).
5. Keep PDF, XLSX, and DOCX disabled until the deployment passes each format's
   processor and browser checks. DOCX also requires PDF.

Follow the [release checklist](../RELEASE-CHECKLIST.md) before inviting users.
The [threat model](../THREAT-MODEL.md) describes the remaining risks.

## Useful commands

| Command | Purpose |
| --- | --- |
| `make install` | Guided first-time installation |
| `make migrate` | Apply pending migrations to an existing installation |
| `make doctor` | Check runtime configuration and enabled processors |
| `make reindex-search` | Rebuild current-version search text; pass options with `REINDEX_ARGS=` |
| `make backup` / `make backup-verify` | Capture data, then check restored artifacts |
| `make quality-full` | Run the aggregate quality gate |

Run PHP tests only through `make test` and browser tests only through `make e2e`.
Both wrappers use isolated temporary databases.

For telemetry, redact `/join/*`, password-reset paths, signed preview queries,
cookies, authorization headers, and private content. Production maintenance on
the artifact host must use the [restricted maintenance procedure](operations/production.md#maintenance).
