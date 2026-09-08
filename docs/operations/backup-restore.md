# Backup and restore

[Operations](../OPERATIONS.md)

## Backup & Restore

App and artifact-host must use the same persistent private artifact volume at
the same `ARTIFACT_STORAGE_ROOT` path. Restore that volume together with PostgreSQL.

ArtifactFlow has two stateful data stores that must be captured together:

- PostgreSQL stores users, workspaces, page metadata, page-version rows,
  office derivative/facts rows, provenance ingests/assertions/external
  references, permissions, audit entries, queues, and durable domain events.
- The private artifacts disk stores untrusted Markdown and single-file HTML
  bytes, normalized PNG/JPEG derivatives, retained PDF/XLSX/DOCX originals,
  canonical XLSX manifests, and derived DOCX preview PDFs. Original image
  uploads are not retained.

Backups must also be paired with secret-manager custody for `APP_KEY`,
`ARTIFACT_URL_SIGNING_KEY`, `IMAGE_PARSER_SHARED_SECRET`, and every enabled
PDF/XLSX/DOCX processor secret. Those keys are not included in data backups and
must not be copied into backup manifests. Losing `APP_KEY` makes encrypted
application data, TOTP secrets, sessions, and trusted-device cookies
unrecoverable. Rotating or losing `ARTIFACT_URL_SIGNING_KEY` invalidates
outstanding signed artifact-preview URLs, which is acceptable for short-lived
previews but must be expected during restore. Parser/processor secrets protect
no data at rest; rotate each one on the app and its matching service together
or the corresponding writes fail closed until they match.

Run a local Compose backup with:

```sh
make backup
```

Keep the three generated files together: `postgres.dump`, `artifacts.tar.gz`,
and `manifest.json` under `backups/<timestamp>/`. The manifest binds both
payloads with a format version and SHA-256 hashes.

The script creates the database dump first, then snapshots private artifacts.
New writes store bytes before committing their version row, so this ordering
avoids missing files for those concurrent writes. It does not protect against
concurrent deletion: pruning or hard deletion between snapshots can leave a
restored row without its file. A hot backup is therefore not point-in-time
consistent across both stores. For strict consistency, quiesce application
writes/deletions or use coordinated snapshots. Verify the restored result.

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

`make backup-verify` runs `artifactflow:verify-artifacts --sample=25` through the app container. Use `make run-app-cmd APP_CMD='php artisan artifactflow:verify-artifacts --all'` for a full check. The command verifies both `page_versions.content_storage_path` originals and every `page_version_derivatives.storage_path`, then reports only aggregate counts for checked, ok, missing-file, and hash-mismatch rows. It must not print private artifact content, signed URLs, database passwords, `APP_KEY`, or `ARTIFACT_URL_SIGNING_KEY`.

Also run `artifactflow:diagnose-2fa` after restore drills. It verifies encrypted 2FA secret readability and reports only aggregate counts so operators can decide whether users should rely on recovery codes or console break-glass.

Retention should match the deployment's recovery objective. A practical self-hosted default is daily encrypted backups with at least 14 restore points, stored away from the application host and access-restricted like production artifact storage. Test a restore regularly, record the backup timestamp and verification counts, and rotate storage credentials separately from application signing keys.

Version-content pruning intentionally retains provenance ingests, assertions, and external
references in PostgreSQL. Page hard deletion removes them. The first provenance slice has no
automatic external-reference expiry/redaction job, so operators must treat database backups as
containing those sensitive references for the full backup-retention period. A future audited
redaction cannot erase older immutable backup copies; recovery and legal-retention policy must
account for that.
