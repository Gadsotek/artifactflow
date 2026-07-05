# Changelog

All notable changes to ArtifactFlow will be documented here.

This project is pre-1.0; expect breaking changes between alpha revisions.

## Alpha (2026-07)

First public release.

- Markdown/wiki pages with a rich editor over portable Markdown source, inline Mermaid diagrams (strict security mode, no external calls), and authorization-aware `[[Page Name]]` wiki links.
- Single-file HTML artifact pages (paste or upload) rendered only from an isolated, cookieless artifact origin behind sandboxed iframes and short-lived HMAC-signed URLs; pre-save draft preview in an opaque no-network sandbox.
- Immutable page versioning with restore, archive/unarchive, and Admin-only hard delete.
- Weighted PostgreSQL full-text search across metadata, tags, and extracted content.
- Personal and shared workspaces with Reader/Editor/Admin roles and per-page access overrides.
- Installation-wide human coworker autocomplete for workspace membership and page access. Human names, emails, and UIDs are intentionally discoverable to authenticated coworkers but never confer authority; service accounts stay out of human pickers and every mutation remains server-authorized. Explicit page Reader and Editor grants do not require workspace membership; page Admin grants do.
- MCP server (app origin only) with scoped, expiring bearer tokens hard-capped to Editor authority.
- TOTP two-factor auth with recovery codes and trusted devices; step-up confirmation on sensitive actions.
- Advisory content scanning with secret blocking on save; durable domain events and an append-only audit trail.
- Docker-based self-hosting with a local Compose quickstart, a multi-role production image, guided install wizard, config doctor, backup/restore tooling, and a fail-closed production boot gate.
- The per-page version limit (`PAGE_MAX_PAGE_VERSIONS`) is a **retention cap**, not a hard wall: appending a new version past the cap prunes the oldest whole version(s) instead of rejecting the edit, so a heavily-edited page can never become uneditable. Retained version content stays immutable; each pruned version is recorded as its own `page.version.pruned` domain event + audit entry, its bytes are released from the workspace storage counter, and its blob is deleted after commit. A post-commit deletion failure can leave an orphan blob; operators can inspect and remove those with the manual `artifactflow:prune-orphan-artifacts` command, while automatic orphan garbage collection remains deferred. Applies to editor, MCP, restore, and revert appends.
- Docker Compose mirrors the published host ports (`APP_PORT`, `ARTIFACT_HOST_PORT`) into the containers, and `artifactflow:doctor` warns when a host port and the URL that embeds it (`APP_URL`, `ARTIFACT_URL`) were not changed together. Local-only usability check; skipped in production and never a boot-gate failure.
