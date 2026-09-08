# Maintenance

[Operations](../OPERATIONS.md)

## Search Maintenance

When page text extraction changes, run the search reindex command to backfill stored page versions from the private artifacts disk and rebuild page search vectors:

```sh
make reindex-search
```

The default command reindexes only each page's current version, which is the version used by search. Pass operator options through `REINDEX_ARGS`, for example `make reindex-search REINDEX_ARGS='--dry-run'`, `make reindex-search REINDEX_ARGS='--page=<uid>'`, or `make reindex-search REINDEX_ARGS='--all-versions'`. Historic (non-current) versions keep only the bounded `source_text`; their `extracted_text` is deliberately cleared when a newer version becomes current, and reindexing does not resurrect it, because restore/revert and reindex re-extract from the stored artifact file. The command prints aggregate counts only and must not output private page content, artifact source, or signed URLs.

## Storage Counters

Workspace storage quotas are enforced against the maintained `workspaces.used_storage_bytes` counter, which the page-version create/delete/move handlers update inside the same transactions under the workspace row lock. The counter charges both retained originals and stored derivatives such as XLSX manifests and DOCX PDF previews. If drift is ever suspected (for example after manual database surgery or a partial restore), reconcile it against both authoritative byte sources:

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:recount-storage'
```

The command reports only aggregate `workspaces=` and `corrected=` counts.

## Orphaned Artifact Files

`artifactflow:verify-artifacts` checks that version rows still have their files.
The orphan reaper checks the reverse: files with no referencing version row.
These can follow interrupted writes or failed post-commit deletion, recorded
as `page.artifact_delete_failed`.

Artifact writes also preserve promoted files when the database transaction
callback completed but the commit acknowledgement was lost. PostgreSQL may have
committed in that state, so deleting immediately could corrupt a committed
version. If the transaction actually rolled back, the preserved files have no
database references and become ordinary age-gated orphan-reaper candidates.

Preview first (report-only, never deletes):

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:prune-orphan-artifacts'
```

Then delete once the report looks right:

```sh
make run-app-cmd APP_CMD='php artisan artifactflow:prune-orphan-artifacts --delete'
```

Files younger than `--min-age-hours` (default 24) are always skipped so the reaper cannot race an in-flight append, whose blob is written just before its version row commits. The command reports only aggregate `scanned=`, `orphans=`, `deleted=`, and `recent_skipped=` counts plus a capped sample of orphan paths; it never prints artifact content or signed URLs.

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

The scheduler runs `artifactflow:prune-credentials` nightly. It removes expired
trusted-device rows after `TRUSTED_DEVICE_RETENTION_DAYS` (default 0) and
revoked/expired MCP tokens after `MCP_TOKEN_RETENTION_DAYS` (default 30).
These credentials already fail authentication; pruning keeps dead hashes from
accumulating while preserving recent token history. Preview with `--dry-run`.
