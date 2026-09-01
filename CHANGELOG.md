# Changelog

All notable changes to ArtifactFlow will be documented here.

This project is pre-1.0; expect breaking changes between alpha revisions.

## Unreleased

### Added

- Added default-off Excel `.xlsx` artifacts with exact private originals, a
  strict isolated SheetJS CE projection service, independently validated typed
  manifests, searchable visible-cell text, and an application-owned read-only
  Tabulator viewer on the cookieless artifact origin.
- Added default-off Word `.docx` artifacts with exact private originals and a
  strict isolated LibreOffice conversion service. The exact PDF derivative must
  pass the separate PDFBox DOCX-preview profile and contain selectable,
  searchable text before it can be persisted or delivered.
- Integrated both office formats with create/replace, immutable versions,
  restore and in-place reprocessing, quota/retention/deletion/integrity
  accounting, current/history preview and authenticated original download,
  permission-aware search, external sharing, and dedicated MCP create/replace/
  read/search/revert behavior.
- Advanced the MCP contract to `0.9.0`; XLSX content reads now require an
  explicit visible worksheet plus canonical range of at most 1,000 cells and
  return a response-size-checked selection instead of an unbounded full
  manifest. Metadata-only workbook reads need no selector.

### Security

- Kept workbook and Word package bytes out of the browser and Laravel parser
  process; processor requests and responses are bounded, HMAC-authenticated,
  replay-resistant, and constrained to single-worker network-denied services.
- Added page/version/purpose/expiry/access-revision-bound office delivery URLs,
  strict artifact-host response policies, safe spreadsheet hyperlink handling,
  and fail-closed feature revalidation across web, external-share, and MCP
  delivery surfaces. Antivirus is deliberately not claimed; isolation remains
  the security boundary.
- Tightened DOCX admission to an exact PNG/JPEG/bounded-EMF media profile and
  explicit ZIP container, compression, Unix-file-type, content-type, and
  relationship-graph checks. EMF admission requires the registered or legacy
  Office media type plus a structurally bounded contiguous record stream and
  rejects driver/OpenGL escape records before LibreOffice. Added authenticated
  live processor health challenges and composite database ownership/kind
  constraints for both Office derivative types.

### Operations

- Added installer planning, independent processor secrets, production boot and
  doctor validation, local/e2e Compose services, restricted artifact-host
  database grants, and public operator/architecture/lifecycle documentation for
  both formats. Make, CI, and tagged releases now build and runtime-test both
  Office processors, prove the DOCX-to-PDFBox searchable-preview chain, scan
  every image, and publish independent XLSX/DOCX digests with SBOM and
  provenance attestations. Final-release `:latest` tags move only after all
  immutable images, attestations, SBOMs, and the GitHub Release exist.
  Production remains default-off pending
  deployment-specific containment and cross-browser evidence.
- The interactive installer now asks about Word before standalone PDF. Selecting
  Word enables the required PDF chain and skips the redundant PDF question;
  declining Word leads to the independent PDF choice and then XLSX. It runs the
  doctor in every environment and requires signed live XLSX plus DOCX/PDF checks
  after local services reload. Isolated PHP/browser test wrappers use the committed
  interpolation guard and no longer read or scaffold the developer `.env`.
- Aligned the public roadmap, workflow, security, self-hosting, MCP, artifact-vault
  guide, and synchronized `llms.txt` copies with the implemented default-off
  Office model, including typed XLSX projection and independently validated
  DOCX PDF previews, instead of describing Word as future-only work.

### Fixed

- Closed a per-page storage-quota undercount during XLSX and DOCX in-place
  reprocessing. Positive derivative growth is now checked under row locks
  against the complete retained version graph; the retention-aware projection
  remains limited to operations that actually append and prune a version.
- Accepted ordinary XLSX ZIP data descriptors with exact CRC/size/boundary
  validation. After complete package validation, SheetJS receives a parser-only
  descriptor-free ZIP assembled from the exact validated compressed payloads;
  the retained workbook remains byte-exact. Unsupported same-workbook hyperlink
  destinations now omit only the link instead of rejecting an otherwise usable
  workbook. Unsafe external schemes still fail closed. Word package thumbnails
  now include common EMF/WMF
  variants behind the exact root thumbnail relationship and are stripped without
  native parsing before LibreOffice.
- Isolated the browser suite's cache and processor admission locks in its
  temporary database. E2E Office requests can no longer collide with a developer's
  simultaneously running local app through the checkout's shared file cache.
- Replaced narrow Office optional-part allowlists with bounded passive package
  profiles. XLSX now accepts standard tables, drawings, images, comments/VML,
  custom XML, thumbnails, pivots, connections, exact worksheet custom-property
  binaries, and similar internal Open XML parts while omitting them from the
  typed manifest. DOCX now accepts standard
  passive chart, diagram, drawing, modern-comment, and custom-XML parts; its
  conversion copy removes custom XML bindings/data, printer payloads, thumbnails,
  embedded fonts, and a settings-owned external attached-template relationship
  before being revalidated. Macros, ActiveX/OLE, embedded packages, all other
  unsafe external relationships, generic binary parts, attacker-defined
  relationship types, DTDs, and package escapes remain rejected.
- Added privacy-safe XLSX rejection diagnostics to processor logs. Responses
  retain the generic rejection contract, while operators receive only an opaque
  fixed-message hash without workbook names, cells, links, or package paths.
- Made DOCX embedded-file/OLE rejection actionable through one fixed allowlisted
  reason. The UI identifies that unsupported feature without exposing package
  paths, filenames, parser errors, or arbitrary processor-supplied details.
- Accepted Word documents containing bounded OOXML obfuscated embedded fonts
  without exposing LibreOffice to the untrusted font programs. The exact original
  remains private and byte-for-byte retained; the processor verifies the fixed
  font-table relationship and content type, strips all embedded-font parts and
  references from a conversion copy, revalidates it, and renders with fallback
  fonts. Wrong content types, external or misbound font relationships, excessive
  font counts, and direct native-font parsing remain rejected.
- Accepted the bounded EMF vector images emitted by real Word documents. The
  processor now verifies the EMF signature, header, declared byte and record
  counts, aligned record boundaries, object-handle ceiling, terminal EOF, image
  relationship, and exact `image/emf` or `image/x-emf` content type. Mislabeled,
  truncated, count-confused, driver-escape, WMF, and SVG inputs still fail
  closed, and the DOCX-to-PDFBox runtime gate covers an embedded EMF.
- Accepted modern Word's passive `stylesWithEffects` companion under its exact
  Microsoft relationship, target, and content type. The DOCX runtime gate now
  also creates a real LibreOffice `.docx` export and proves that preflight and
  PDF conversion accept it while misbound style relationships still fail.
- Fixed XLSX processor authentication for installer-generated `base64:`
  secrets. Laravel, the deployment doctor, processor startup, and the container
  healthcheck now use the same decoded HMAC key bytes; the production-image
  runtime gate proves a Base64-configured service accepts the decoded
  application-side challenge.
- Prevented the XLSX processor from advertising readiness before its cold
  SheetJS worker path is usable. Startup now runs one bounded child-process
  rejection self-test before binding the service socket, and container health
  start periods cover that fail-closed readiness check.
- Made storage recount and the admin workspace/page usage breakdown include
  XLSX manifest and DOCX PDF-preview derivatives. Recounting can no longer
  remove derivative bytes from the quota counter.
- Aligned historical XLSX preview sandbox permissions with the current and
  external-share viewers so reviewed safe hyperlinks remain usable without an
  opener or referrer, and added both Office ADRs to the public-doc allowlist.
- Fixed the standalone XLSX maximum-row stress fixture and added Office MCP
  regressions proving scope, token-ceiling, concurrency, and Base64 checks run
  before processor dispatch.
- Fixed unknown commit-outcome handling for create, update, restore, and Office
  reprocess operations. Failures after transaction callback completion now
  preserve the complete promoted original/derivative graph because PostgreSQL
  may already have committed it; genuine rollback orphans are removed later by
  the age-gated, reference-aware orphan reaper.
- Completed the XLSX viewer's dark theme across headers, rows, borders, hover
  and selection states, links, empty-sheet messaging, and the formula bar. The
  empty `No formula` state now uses a distinct neutral high-contrast style.

## v0.1.0 — 2026-08-28

Feature, security, and deployment release. It makes the completed native-text
PDF slice available as an explicit production opt-in and advances the MCP
server contract to `0.7.0` with a typed application boundary, selective reads,
and a breaking alpha response-shape cleanup. Existing installs keep
`PDF_PROCESSOR_ENABLED=false` by default, and the local networkless Unix-socket
processor topology is unchanged.

### Added

- Added a dedicated non-root private-network PDF processor image for platforms
  that cannot mount the local Unix socket. Its PHP server and every PDFBox child
  inherit a self-installed seccomp filter that denies outbound connects,
  destination-bearing sends, SCTP/packet/netlink sockets, and io_uring; startup
  fails unless TCP, UDP, and SCTP denial self-tests return `EPERM`. Its health
  probe now fails when either the HTTP listener or the PDFBox engine is
  unavailable.
- Added a fail-closed per-engine seccomp boundary to both PDF processor
  topologies. It permits JVM threads while denying child-process creation, with
  a production-image regression proving timed-out native work cannot survive to
  inspect a later request's temporary PDF input.
- Restricted the private-network processor's engine-aware `/health` route to
  direct loopback peers and added a fresh domain-separated HMAC to both service
  health probes. Private-network requests, including traffic forwarded by a
  loopback TLS sidecar, cannot acquire the native engine lease without the
  processor secret.
- Tagged releases now publish, scan, attest, and attach an SBOM for
  `ghcr.io/gadsotek/artifactflow-pdf-processor` independently of the main app
  image so deployments can pin each immutable digest.

### Changed

- Refactored all MCP application tool inputs, success payloads, errors, and
  shared response shapes into immutable named DTOs with one explicit
  whitelist-based wire encoder. Laravel schema/request arrays and final JSON
  projections remain at the adapter edge; tool handlers no longer exchange
  anonymous response arrays or accept the generic argument bag.
- Advanced the MCP server contract from `0.6.0` to `0.7.0`. `read` now accepts
  optional `content` and `provenance` sections, including an empty metadata-only
  selection that skips unrequested storage, image, and provenance work after
  normal authorization. Full reads remain the default.
- **Breaking alpha MCP response change:** full-read provenance now defines each
  assertion once in a `producers` catalog and uses producer UID lists for page
  origin, direct version, and effective content-origin lineage. The legacy
  repeated producer fields are not emitted in parallel. Absent optional
  descriptions, change summaries, producer identities, MCP client fields, and
  external-reference values are now omitted instead of returned as empty
  untrusted-data envelopes; every present untrusted value keeps its full
  field-level envelope.
- Replaced the unconditional production PDF block with strict opt-in
  validation of the processor origin, optional Unix socket, dedicated HMAC
  secret distinct from the image-parser credential, and bounded timeouts.
  Artifact hosts may present enabled PDFs without processor credentials;
  worker and scheduler roles reject PDF enablement.
- Extended `artifactflow:doctor`, production image gates, and operator docs to
  verify both supported processor topologies while preserving the default-off
  backward-compatible behavior.
- Required HTTPS for production PDF processor traffic whenever no Unix socket
  is configured, preventing private PDF bytes and extracted text from crossing
  a network in plaintext.

### Security

- Preserved the same live token/page authorization, hierarchy non-disclosure,
  parser/format gates, optimistic concurrency, scanning, event, and audit
  boundaries across selective reads and typed payload construction.
  `update_description` deliberately retains both concurrency values: one binds
  text to the observed content version and one protects concurrent metadata
  changes.

### Fixed

- Made production boot and `artifactflow:doctor` reject PDF processor URL,
  socket, or credential configuration on every non-app runtime role, while
  removing the unused built-in URL so default-off roles start without an
  explicit empty override.
- Updated the application, isolated image-parser, and PDF processor OpenSSL
  packages to the Alpine revisions that fix CVE-2026-14456 and restored the
  production-image Trivy gate.

### Dependencies

- Updated the pinned `mcp-remote` bridge from 0.1.38 to 0.2.1 and synchronized
  its lockfile, reviewed integrity pin, runtime verification path, and
  connection/runtime guards.
- Updated Mermaid from 11.17.0 to 11.17.1 and synchronized the strict-renderer
  dependency assertion.
- Updated ESLint from 10.8.1 to 10.9.1.
- Updated `symplify/easy-coding-standard` from 13.2.18 to 13.2.19.

## v0.0.11 — 2026-08-25

Feature, security, and dependency maintenance release. It adds the default-off
searchable PDF artifact milestone, completes its isolated local processor and
installer path, and refreshes the PHP and frontend toolchains. PDF support is
still experimental and must remain disabled in production; existing
installations must run the new migrations before serving this release.

### Added

- Added a default-off searchable PDF application boundary: bounded synchronous
  PDFBox validation/text extraction, immutable original storage, permission-aware
  search, current/history native preview and forced download on the artifact
  origin, restore, standalone derived-fact reprocessing, full lifecycle and
  operator coverage, and permission-first MCP create/replace/read/search/revert.
  External PDF sharing is implemented behind the default-off PDF feature gate,
  reusing the existing revocable anonymous session capability and isolated
  native viewer. OCR, raster/HTML preview conversion, raw PDF MCP responses,
  and production enablement remain deliberately closed. (#83)

### Security

- Added purpose- and revision-bound PDF delivery capabilities, exact stored-byte
  hash/size verification, strict response headers, renamed-HTML/active-structure
  hostile fixtures, a fail-closed feature switch across app and artifact
  runtimes, and a cross-process native-engine lease that preserves the
  concurrency-one memory boundary during health probes. MCP checks exact
  authority before Base64 decoding/native work and returns only enveloped text
  plus safe facts; restore/reprocess verify retained original hashes before
  processing, and PDF diagnostics stay out of audit/event metadata. (#83)
- Hardened local image/PDF processor isolation, socket-path validation, health
  checks, bounded response handling, and secret provisioning. Published or
  encoded secrets are rejected instead of reused or overwritten, and runtime
  network-isolation checks pin the processors' no-network boundary. (#87)

### Changed

- Local `make up` starts the isolated PDF processor and Reverb while retaining
  their application-level feature gates. The guided local/test installer now
  offers a default-No experimental PDF choice, with `--pdf` for unattended
  installs; production installation continues to reject PDF enablement until
  its release gate is accepted. (#87)

### Fixed

- PDF validation failures now report a stable, format-specific rejection reason,
  preserve selected mode and metadata across create-form redirects, and clearly
  prompt for the file to be selected again. Reprocessing presents the same safe
  reason without exposing native parser diagnostics. (#92)
- Clarified that page-access autocomplete grants existing registered human
  coworkers access and does not invite or email external recipients. (#93)

### Dependencies

- Updated Laravel Framework 13.25.0 → 13.26.1, Mockery 1.6.13 → 1.6.15,
  and Rector 2.6.2 → 2.6.3. (#85, #88)
- Updated Resend PHP 1.9.0 → 1.10.0 and Easy Coding Standard 13.2.17 →
  13.2.18. (#90)
- Updated Mermaid 11.16.1 → 11.17.0 and Vite 8.2.1 → 8.2.2, and refreshed
  the pinned Mermaid security invariant for the reviewed release. (#91)

### Internal / Tooling

- Added a checked Codex filesystem-permission profile and aligned the AI guard
  harness with it so repository access restrictions are continuously verified.
  (#86)

## v0.0.10 — 2026-08-19

Dependency and CI maintenance release. It refreshes the PHP and frontend
toolchains and makes required CI and nightly audits faster and more observable.
There are no schema changes, product features, or new operator configuration
requirements.

### Dependencies

- Updated Laravel MCP 0.9.2 → 0.9.4, Resend PHP 1.7.0 → 1.9.0, and
  Google2FA 9.0.0 → 9.1.0. (#75, #79, #80, #81)
- Updated Rector 2.6.1 → 2.6.2 and Mockery 1.6.12 → 1.6.13. (#75, #81)
- Updated Globals 17.9.0 → 17.11.0, Laravel Vite Plugin 3.1.3 → 3.2.0,
  and Concurrently 10.0.4 → 10.0.5. (#76, #78, #82)

### Internal / Tooling

- Split browser coverage from PHP quality work so required CI can run those
  independent gates in parallel, while retaining the exact aggregate release
  gate and a single writer for the shared development-image cache. (#74, #77)
- Made nightly audits finish all runnable checks, retain per-task logs, and
  report their aggregate result instead of stopping at the first failure. (#74)
- Scheduled Composer and npm dependency updates ahead of the nightly audit and
  moved pinned Docker image updates to a non-auto-merging Renovate configuration
  that groups repeated Node pins in one pull request. (#74)
- Made line coverage fail explicitly when PCOV is unavailable instead of
  silently producing an invalid coverage run. (#74)

## v0.0.9 — 2026-08-15

Feature and security maintenance release. It adds three-level nested shared
workspaces and clears the nightly production-image audit findings.

### Added

- Added three-level nested shared workspaces with downward membership
  inheritance, opt-out boundaries and per-user exclusions, direct child
  memberships, serialized reparenting, exact-workspace Library/category/storage
  behavior, and exact selected-workspace MCP scopes. Authorization, preview
  revision, realtime revocation, and non-disclosure checks cover descendants and
  hierarchy changes. (#71)

### Security

- Rebuilt FrankenPHP with Go 1.26.6, clearing CVE-2026-39821 and
  CVE-2026-46600 from the production-image Trivy scan.

### Internal / Tooling

- Pinned the verified FrankenPHP builder digest and made the production build
  assert the expected patched Go toolchain before compiling the binary.

## v0.0.8 — 2026-08-13

Dependency maintenance release. It refreshes the production and development
image pins, updates the Laravel and frontend toolchains within their existing
major versions, and advances the release provenance action. There are no schema
changes or new operator configuration requirements.

### Dependencies

- Updated Laravel Framework 13.23.0 → 13.25.0, Laravel MCP 0.9.1 → 0.9.2, Laravel Reverb 1.11.0 → 1.11.1, and Rector 2.5.9 → 2.6.1. (#68)
- Updated CodeMirror HTML 6.4.11 → 6.4.12, CodeMirror Markdown 6.5.1 → 6.5.2, ESLint 10.8.0 → 10.8.1, and Vite 8.2.0 → 8.2.1. (#69)
- Refreshed the pinned PHP 8.5.9, Node 26 Alpine, and FrankenPHP v1 image digests. (#65, #66, #67)
- Updated `actions/attest-build-provenance` 4.1.1 → 4.2.2 for tagged-release provenance generation. (#64)

### Internal / Tooling

- Kept the Dockerfile and Compose Node pins synchronized after the image refresh. (#66)
- Updated the DCO validator to recognize Dependabot's isolated final sign-off after its metadata block, while retaining strict rejection of body-text lookalikes. (#66, #68)
- Removed storage-path type guards made redundant by the updated static-analysis types. (#68)

## v0.0.7 — 2026-08-10

Major alpha feature and security release. It adds normalized image artifacts,
external page sharing, version-level AI provenance, expanded MCP organization
and upload capabilities, richer catalog discovery with gated realtime updates,
and optional Turnstile protection, alongside substantial sandbox, identity,
rate-limiter, and deployment hardening.

### Security

- Closed eight adversarial resource- and boundary-control gaps: exhausted account-wide login budgets now apply before credential validation; anonymous share traffic has a selector-independent source ceiling and indexed, cursor-based database-counter retention; Markdown and artifact-preview parsing have deterministic complexity limits with fixed safe fallbacks; duplicate declarative-shadow attributes rewrite in linear time; one-time MCP tokens and 2FA bootstrap material are explicitly non-cacheable; and artifact-host PostgreSQL grants now isolate its limiter table from application login, 2FA, reset, MCP, and write counters. The production gate requires distinct database limiter table names regardless of connection aliases and fails closed for Redis, Memcached, and DynamoDB limiter aliases whose physical credential/namespace boundary cannot be proven from cache configuration.

### Added

- Added explicit MCP organization and binary-ingestion boundaries: `mcp:organize` powers revision-safe title/hierarchy/category/tag changes and standalone taxonomy creation, while `mcp:upload` combines with create/update for isolated, normalized PNG/JPEG image creation and replacement. MCP page creation now accepts a real parent, and image revert restores retained normalized bytes without re-encoding.
- Added alpha external page sharing through one-time or expiring bearer links, with fragment-only secret bootstrap, explicit one-time redemption, window-bound anonymous viewing, live page/policy revalidation, isolated Markdown/HTML/image presentation, authorized inventory and revocation, and the dedicated MCP `mcp:share` scope for owned editable pages in opted-in workspaces.
- Added optional 255-character change summaries to immutable page versions, shown in browser history and exposed to MCP as untrusted data. MCP create, update, and revert writes require a summary.
- Added version-level AI artifact provenance: immutable observed ingest facts, optional MCP-declared AI/human/software producers with exact or partial provider/model identity, bounded forward-compatible identity extensions, external origin references, restore lineage, authorized web/MCP inspection, write receipts, and provider/model search scopes. Declared claims remain explicitly self-reported and separate from unverified MCP-reported client metadata.
- Added optional, deployment-configured Cloudflare Turnstile on password login and both password-recovery forms. Supplying both keys renders action-bound challenges and enables fail-closed server verification; installations without keys retain the self-contained authentication path.
- Added versioned PNG/JPEG image and screenshot artifacts. Uploads are format/extension checked, bounded by byte/dimension/pixel limits, decoded and re-encoded without metadata or appended payloads, and previewed through a fixed scriptless document on the isolated artifact origin.
- Added MCP image content reads and an Editor-capped `update_description` tool with content-version plus metadata concurrency, scanning, search refresh, throttling, and token/session audit attribution. Image artifacts intentionally have no OCR; full-text discovery uses editable catalog metadata.
- Local stack setup and the guided local/test installer now provision a dedicated image-parser shared secret alongside the application and artifact-signing keys; production continues to require externally managed secrets before install.

### Changed

- Production upgrade: run the new migrations before serving traffic to create the indexed `rate_limit_cache*` and `artifact_rate_limit_cache*` tables; set `CACHE_LIMITER=database_limiter` and `ARTIFACT_CACHE_LIMITER=database_artifact_limiter` on every app, artifact-host, worker, and scheduler runtime; and give artifact-host its own `DB_USERNAME`/`DB_PASSWORD` PostgreSQL role with the reviewed `docs/operations/artifact-host-database-grants.sql` grants applied after migration. Keep the scheduler running so expired database counters are pruned nightly. The table names can be overridden with `DB_RATE_LIMIT_CACHE_TABLE`, `DB_RATE_LIMIT_CACHE_LOCK_TABLE`, `DB_ARTIFACT_RATE_LIMIT_CACHE_TABLE`, and `DB_ARTIFACT_RATE_LIMIT_CACHE_LOCK_TABLE`, but the application and artifact table names must remain distinct. `EXTERNAL_SHARE_PUBLIC_IP_RATE_LIMIT_PER_MINUTE` is also new and defaults to `60`.
- MCP discovery now tells AI clients to generate single-file, self-contained HTML artifacts with inline dependencies and no CDN or network-dependent behavior.
- Simplified the login screen by removing the operator-facing public-registration and rate-limiting note; the authentication controls remain documented and unchanged.

### Fixed

- Preserved every safe supplied MCP provenance field when exact model identity is incomplete, while continuing to reject prompt/reasoning, credential, authorization, URL, and blob/content payload classes. MCP writes now echo the stored provenance precision instead of making the agent infer whether a claim survived validation.
- Fixed local Reverb startup and the production-shaped origin probe after Reverb's restart signal moved to the database cache: normal Reverb now receives the app database credential, while the isolated origin probe uses a connection-free restart cache without weakening its dedicated production limiter configuration.
- Corrected the JPEG threat-model contract to describe the implemented bound: at most 256 pre-frame markers inside the 5 MiB upload envelope, with length-prefixed metadata skipped rather than an independent 1 MiB header cap.
- External-share inventory now coarsely refreshes `last_viewed_at` after successful viewer-content and preview-URL resolution, and redeemed one-time rows explicitly show when their window-lived view session is still open and revocable.
- External-share viewer throttling now preserves the route's uniform HTML unavailable surface and rate-limit headers, including preview-URL renewal. Transient viewer failures retain the per-window proof so a redeemed one-time link can recover on reload. Expiring-share windows now keep independent path-scoped view credentials, while view authorization stays locked through response materialization so concurrent revocation cannot serve stale content. Expiring shares cap retained viewer sessions and evict the oldest at the configured ceiling.
- The AI commit guard now distinguishes real DCO sign-off options from option arguments that merely contain a lowercase `s`.
- Stabilized the external-share authorization concurrency proof by isolating the forked worker on a dedicated database connection and keeping it alive until the parent transaction completes.
- Hardened provenance by blocking obvious credential patterns, keeping user-controlled producer identifiers out of event/audit metadata, bounding the denormalized search projection, resolving restore origins in constant database work, and labelling MCP `clientInfo` as caller-reported rather than observed identity.
- MCP create tools now identify an inaccessible target as `Workspace not found.` while retaining the opaque `not_found` response used to prevent workspace disclosure.
- MCP search retains its tolerant tag-filter contract: blank and duplicate strings are normalized away, arbitrary identifiers remain non-fatal, and no new search-specific item ceiling rejects previously accepted requests. Bounded string-list callers now apply their maximum to the effective normalized list.

### Security

- Administration entry now requires a live TOTP or single-use recovery code instead of the account password, with dedicated per-account minute/hour and per-IP limits. Two-factor enable, disable, recovery-code rotation, and revoking all trusted devices advance the authentication revision, rotate and exactly rebind the acting session, and reject stale requests under the user-row lock so concurrent credential mutation cannot resurrect a session or trusted device. Trusted-device issuance takes the same user-row lock and refuses a proof from an earlier authentication revision, making completed reset, recovery-code rotation, and revoke-all operations final against in-flight issuance. Single-device revocation also locks user then device, preserving the common credential lock order instead of deadlocking with reset or revoke-all.
- Claude and Codex MCP configuration install `mcp-remote@0.1.38` from a reviewed SHA-512 integrity lock into a lock-fingerprinted directory, enforce its Node engine floor, and launch its absolute entrypoint directly with Node.js so client working-directory behavior and `npx` resolution cannot break the connection. The authenticated direct-entrypoint path is verified nightly from an unrelated working directory, and lock upgrades cannot overwrite the last-known-good install before succeeding.
- Every artifact-origin response now receives a bare sandbox CSP at the edge, including static files, synthetic OPTIONS responses, maintenance responses, and Caddy-generated errors. Real 429/runtime-role fallback regressions and a cross-engine top-level-notice test lock the PHP/edge wiring while preserving the server-rendered return link without granting artifact documents top-navigation capability.
- Closed artifact-preview parser differentials for overlapping script comment endings, foreign-content `</p>`/`</br>` breakouts, and foreign elements ignored in `select`/`frameset` insertion modes. The expanded corpus also found and closed a Chromium/WebKit `select`/`noframes` raw-text divergence; all cases are mandatory guard-free regressions in Chromium, Firefox, and WebKit.
- Static refresh metas and resource hints now normalize decimal and hexadecimal numeric character references with the browser's missing-semicolon behavior before classification. This closes a confirmed WebKit TCP preconnect callback and all-engine artifact-frame self-navigation from entity-bypassed tags; the full-stack regression inspects the rewritten response and requires zero live collector hits. Mermaid directives cannot reopen HTML-label configuration, the image responder locally rejects media types other than PNG/JPEG, and the preview guard covers the safe Sanitizer API markup entry points without claiming that legacy-unforgeable `top` or `location` members were replaced.
- Added a bounded, seed-reproducible artifact-parser differential fuzzer to the required
  cross-engine browser corpus. It generates tokenizer/tree-builder mutations, runs the exact
  response rewriter without runtime DOM cleanup, and requires zero nested frames in Chromium,
  Firefox, and WebKit; CI rotates the reproducible corpus from the commit SHA.
- Closed the Firefox tree-builder differential found by that corpus: a `frameset` start ignored
  inside `select` could make the rewriter incorrectly enter `noframes` raw text and leave a later
  nested browsing context live.
- Closed a MathML `annotation-xml` to SVG tree-builder differential that could make the response rewriter treat SVG descendants as HTML integration points and miss a live nested browsing context.
- Provenance URLs and opaque external references are HTTPS/shape bounded, inherit page authorization, render as escaped `noopener noreferrer` links, and are excluded from logs, audit metadata, domain-event payloads, and full-text search.
- Bound Turnstile verification to distinct login, reset-link-request, and password-reset actions plus the configured application hostname; rejected partial/test/role-leaking production configuration; returned clear non-production guidance for partial key pairs and malformed enabled settings; scoped Cloudflare CSP access to those three GET forms; added secret-free bounded Siteverify diagnostics and real-widget browser regressions against all three Laravel auth pages using Cloudflare's public test site key; and kept the existing login and password-reset rate limits as the abuse backstop.
- Prevented workspace Editors who own a page from downgrading an existing page-Admin grant; changing or revoking Admin authority now consistently requires hard-delete authority.
- Closed artifact-preview tree-builder differentials where an ignored raw-text start tag, or a recognized `script`/`noframes` raw-text carrier containing a fake container close, could desynchronize the response rewriter inside an active HTML `select` or `frameset` and leave a later nested browsing context live. The tagged draft-preview regression pins the served inert templates and verifies zero UDP across Chromium, Firefox, and WebKit.
- Closed artifact-preview parser differentials where SVG/MathML `style`, SVG/MathML `plaintext`, SVG `script`, MathML `script`, and scripting-enabled HTML `noscript` content could hide a live nested browsing context from the response rewriter. Static declarative-shadow-root attributes are neutralized before parsing so open or closed shadow trees cannot hide a missed context from the residual DOM sweep. The real draft-preview regression pins the served templates and verifies zero UDP across Chromium, Firefox, and WebKit.
- Closed an artifact-preview script-data tokenizer differential where a `</script>` encountered in the double-escaped state could desynchronize the response rewriter and leave a later nested browsing context live. The scanner now follows data, escaped, double-escaped, and comment-end transitions before accepting the actual closing tag; carrier/depth regressions pin inert output and zero UDP across Chromium, Firefox, and WebKit.
- Closed a Firefox-only foreign-CDATA differential where an integration-point raw-text carrier could make the response rewriter skip a later nested browsing context. CDATA at HTML integration points now follows both Firefox's `]]>` boundary and the maintained Chromium/WebKit bogus-comment interpretation, while ordinary foreign CDATA remains verbatim; cross-engine regressions cover suffix, inline-child, and preservation cases.
- Clarified that the external-share `sessionStorage` proof denies independently opened same-cookie windows but is window-lifecycle friction, not recipient identity or DRM: an authorized recipient can deliberately clone client-held state just as they can copy rendered bytes.
- Closed an artifact-preview tokenizer differential where a slash inside an unquoted SVG/MathML attribute value could be mistaken for the tokenizer's self-closing flag and suppress nested-context neutralization. Resource hints and meta refresh elements are now neutralized synchronously across insertion, markup-parsing, attribute/accessor, attribute-node, and `relList` mutation sinks instead of waiting for the mutation observer; every inspected WebIDL string is coerced once and only that primitive reaches the native sink, preventing stateful-coercion bypasses. Artifact responses additionally opt out of DNS prefetching through a server-delivered header.
- Added a dedicated per-user rate limit for post-authentication 2FA disable and recovery-code regeneration attempts. Production Reverb secrets must now be distinct from application and artifact-signing keys, and every repository-published database credential fixture is rejected consistently by the boot gate and Deployment Doctor.
- Original image upload containers are discarded after normalization. SVG and malformed or mismatched image uploads are rejected, and current/historical raster previews use an empty iframe/CSP sandbox without script capability.
- Moved native PNG/JPEG decoding and EXIF handling out of the production application image into a non-root, read-only, resource-capped parser container on an internal-only network. Requests and normalized responses are nonce-bound and HMAC authenticated; the parser receives no app source, database credentials, artifact storage, public port, or outbound route.
- Bounded application-side JPEG header inspection by marker count and header bytes, rejected restart markers before scan data, and constrained each 512 MiB parser container to one normalization process so hostile marker floods or concurrent maximum-pixel decodes cannot exhaust application/parser CPU and memory.
- Reduced and hard-capped new image uploads at 16 Mi pixels while retaining the 40 Mi-pixel historical read envelope. Added a shared non-blocking parser admission slot, exact-pixel per-user and installation-wide work budgets, retryable 429/503 backpressure, production configuration checks, and a parser startup refusal for prefork worker counts above one.
- Added parser-side PNG chunk, CRC, and bounded-IDAT validation before GD. The zlib stream must terminate at exactly the scanline bytes implied by IHDR, including Adam7 passes, so tiny declared dimensions cannot hide gigabytes of trailing decompression work behind a one-pixel budget charge.
- Strip PNG text/profile metadata (`zTXt`, `iTXt`, and `iCCP`) before native decoding so high-ratio payloads cannot bypass pixel admission or OOM-kill the parser.
- Image page and history rendering now checks binary availability without reading complete raster bodies on the app origin, and authenticated or external scriptless previews no longer enter HTML renewal or re-download paths.
- Made parser-secret isolation fail closed for every non-app production runtime role, removed unused WebP support from the parser image, and changed its health endpoint to exercise a real one-pixel PNG decode/re-encode. Deprecated no-op GD destruction calls were replaced with reference release.

## v0.0.6 — 2026-07-25

Security maintenance release. It clears the nightly dependency and production-image audit findings without adding product features.

### Security

- Updated DOMPurify to 3.4.12 and `brace-expansion` to 5.0.8 to clear the npm audit findings. (#36)
- Rebuilt the production FrankenPHP binary with `kin-openapi` v0.144.0 and pinned an embedded-module verification check, clearing GHSA-r277-6w6q-xmqw from the Trivy image scan. (#36)

### Internal / Tooling

- Made DCO trailer parsing independent of the current checkout layout so Docker-backed validation also works from linked worktrees. (#36)

## v0.0.5 — 2026-07-23

Security and tooling release. It closes a declarative-shadow-DOM sandbox escape in the artifact preview, re-enables cross-engine end-to-end coverage on the artifact-security corpus, and refreshes dependencies.

### Security

- Disabled the declarative-shadow-DOM parser entry points (`Document.parseHTMLUnsafe`, `Element.prototype.setHTMLUnsafe`, `ShadowRoot.prototype.setHTMLUnsafe`) in the artifact-preview guard instead of sanitizing their input. Input sanitization could not reach a nested browsing context hidden inside a declarative shadow root, which the parser materialized into a live, script-executing frame — a bypass of the nested-context defense confirmed by an empirical Chromium harness and now covered by draft-preview and saved-artifact end-to-end tests. (#23)

### Internal / Tooling

- Re-enabled cross-engine end-to-end coverage: the full Playwright suite runs on Chromium and the `@artifact-security` corpus additionally on Firefox and WebKit, guarded by an atomic run lock over the shared services and a configuration check that pins the cross-engine title list and forbids `networkidle`. (#30)
- Exempted Dependabot from the DCO sign-off gate so automated dependency pull requests satisfy the required checks. (#28)

### Dependencies

- Bumped `laravel/framework`, `laravel/reverb`, `resend/resend-php`, `tailwindcss`, and related minor and patch dependencies, and refreshed the FrankenPHP/PHP base image digest. (#25, #26, #27)

## v0.0.4 — 2026-07-22

Security release. It binds browser sessions to the user authentication revision, closes the remaining old-password login race, makes the MCP transport fully session-free, clears the grpc-go HIGH finding from the production image, adopts laravel/mcp v0.9.1 with the upstreamed malformed-parameter fix, and prompts users to review surviving MCP tokens after a password reset.

### Security

- Bound authenticated browser sessions to the user authentication revision, closing the verified race where an old-password login could finish concurrently with a completed password reset. Sessions created before this release are logged out once on upgrade. (#21)
- Prompted users once after their first successful post-reset login to review any active MCP tokens that deliberately survived the password reset. (#21)
- Made every Laravel MCP HTTP route session-free and applied the pre-authenticated source-IP limiter to the package-generated compatibility methods as well as POST requests. (#21)
- Rebuilt the production FrankenPHP binary with grpc-go v1.82.1, clearing GHSA-hrxh-6v49-42gf from the nightly image audit while upstream still resolves the vulnerable dependency. (#21)

### Fixed

- Returned the retryable JSON-RPC installation-readiness response for every MCP transport method, including package-generated GET and DELETE compatibility routes. (#21)
- Required the artifact read ceiling to cover both Markdown and HTML write ceilings at validation, application, production-startup, diagnostic, and database boundaries. (#21)
- Advanced page metadata revisions when member removal reassigns ownership, so an already-open metadata form receives a conflict instead of overwriting the chosen replacement owner. (#21)

### Changed

- Upgraded laravel/mcp to v0.9.1, which ships our upstreamed malformed JSON-RPC parameter fix, and removed the local server-side shim it replaces. List-shaped tool arguments are now rejected at the protocol layer (-32602) instead of reaching tool-level validation. (#21)

## v0.0.3 — 2026-07-21

Security and correctness release. It completes the official Laravel MCP migration, closes the remaining authentication and concurrent-mutation races found by adversarial review, repairs rich-Markdown serialization, and hardens installation, backup, restore, and release operations.

### Security

- Replaced the hand-written MCP JSON-RPC transport with the official `laravel/mcp` package while preserving app-origin routing, installation readiness, scoped bearer tokens, the Editor authority ceiling, throttling, untrusted-data envelopes, and audit attribution. (#16)
- Serialized password reset and invitation workflows, invalidated stale 2FA login challenges after password changes, and made invitation delivery transactional without exposing invitation bearer data. (#16)
- Revalidated the locked user and authentication revision during MCP token issuance, closing the race where a token could otherwise be issued from stale password/TOTP verification state. Existing MCP tokens deliberately survive an ordinary password reset; operators can revoke them separately after a suspected compromise. (#17)
- Closed additional artifact-preview HTML tokenizer differentials while preserving the two-origin, opaque sandbox boundary. (#16)
- Tightened the fail-closed production gate and read-only doctor checks for database TLS root certificates, deliverable mail configuration, runtime role state, bootstrap credentials, and migration readiness. (#17)
- Added one-shot password-file inputs for administrative console commands so credentials need not appear in process arguments or shell history. (#17)

### Fixed

- Fixed rich-Markdown list and inline-format serialization that could absorb subsequent list items and code blocks into bold text. (#16)
- Made page metadata updates revision-aware under row lock, including workspace moves, so stale forms return `409` instead of overwriting newer ownership or metadata. (#17)
- Enforced hard-delete title confirmation under the page lock and mapped concurrent user-email uniqueness conflicts to the documented domain error. (#17)
- Made MCP token revocation report only the process that performed the actual state transition during concurrent revocations. (#17)
- Removed a fail-open installation-readiness memoization path in long-running workers. (#17)

### Changed

- Extended MCP client connection setup to discover and explicitly select among Claude Desktop, Claude Code, Codex homes, and Codex profiles without storing bearer tokens in repository configuration. (#16)
- Backup manifests now include a format version and SHA-256 hashes for both payloads. Restore verifies integrity before changing service state, requires application roles to be quiescent, and offers an explicit legacy-manifest upgrade path. (#17)
- Workspace names and MCP token names reject non-storable control characters at the validation boundary. (#17)

### Internal / Tooling

- Tag-driven releases now depend on the complete CI workflow before publishing images or GitHub Releases. (#17)
- Granted the reusable release CI gate its required read-only pull-request scope so tag workflows pass GitHub's startup validation.
- Expanded deterministic concurrency, deployment-gate, backup/restore, editor, sandbox-parser, and operational regression coverage. (#16, #17)
- Stabilized the saved-preview recovery E2E test by synchronizing on parent-observed iframe loads and the renewal response instead of racing the artifact's self-navigating document.

### Dependencies

- Added `laravel/mcp` 0.9.x and updated the locked Guzzle/PSR-7 and `shell-quote` versions to resolve published advisories. (#16)

## v0.0.2 — 2026-07-19

Security-hardening release. No new end-user features; it tightens the untrusted-artifact isolation boundary, closes several artifact-preview parser differentials, hardens mass assignment and rate limiting, and patches the base image.

### Security

- Artifact preview blocks nested browsing contexts and WebRTC egress: static `iframe`/`frame`/`fencedframe`/`portal` tags are neutralized server-side, and the early guard removes fresh `srcdoc`/`about:blank` child realms that bypass parent-realm API patches. A real UDP STUN listener regression-tests that no packet escapes. (#6)
- Hardened that nested-context neutralization against HTML parser differentials, including a neutralized iframe's inert `template` wrapper being closed from its raw-text interior. (#10)
- Closed comment- and declaration-parser differentials in the artifact-preview hardener. (#11)
- Require the artifact storage root to live outside the public web root; the production boot gate now fails closed otherwise. (#12)
- Rate limiting now requires a persistent cache store in production (boot gate), and workspace invitation tokens are stored hashed. (#13)
- Archiving a page increments `preview_access_revision`, so outstanding signed preview URLs are invalidated immediately on archive instead of waiting out the TTL.
- Locked down mass assignment on credential, authority, immutable-content, and installation-settings models (`McpAccessToken`, `TrustedDevice`, `PageVersion`, `InstallationSettings`) with `$guarded = ['*']`.
- Disabled legacy `document.execCommand('insertHTML')` inside artifact previews so it cannot create a nested browsing context during the MutationObserver microtask window; the advisory scanner and browser attack corpus now pin the rule.
- Production rate limiting now requires a shared database, Redis, Memcached, or DynamoDB counter backend; node-local file caches are rejected so limits cannot multiply across app replicas.

### Fixed

- Content saves keep succeeding when the after-commit realtime broadcast fails. (#8)
- Fresh and partially migrated deployments now return a secured, session-free setup-required response instead of exposing a missing-database-table exception; `/up` stays available during installation. The same manifest-aware gate covers MCP before token authentication and returns a retryable JSON-RPC 503 until migrations are current.

### Changed

- The saved-artifact preview-ready recovery signal is now a per-load nonce handshake (the parent posts a nonce; the opaque-origin document echoes it back), so a stale or pre-sent signal can't suppress URL recovery.
- A password login now opens a visible three-minute window to start and finish first-time 2FA enrollment without immediately re-entering the same password; expiry returns to password confirmation and invalidates the pending QR/secret so restarting generates a fresh one.
- The 2FA login challenge now presents recovery-code entry as an explicit alternate mode, hidden until requested; invalid authenticator and recovery values are excluded from flashed session input.
- Search ranking/match SQL moved to static `literal-string` constants; behavior is unchanged and user input stays parameterized.
- Threat model clarified: the full set of transitions that invalidate signed URLs, both embedding-iframe surfaces (current and historical version), and the ready-signal handshake.
- Operations guidance now distinguishes ordinary network APIs from the accepted self-navigation residual and requires upstream log redaction for invitation/reset bearer URLs.

### Internal / Tooling

- Broadened the best-effort AI agent guard hooks and documented the repository-shipped hooks. (#4, #7)
- Added architecture and infrastructure contract tests (mass-assignment, raw-SQL tripwire, workspace-scoped foreign keys, AI-harness drift, DCO validation) plus Semgrep rules, an explicit positive/negative Semgrep fixture corpus, and a Rector dry-run (`composer rector`).

### Dependencies

- Bumped the FrankenPHP production base image, clearing Go CVE-2026-39822 (`os.Root` symlink traversal); c-ares held at ≥ 1.34.8-r0 for CVE-2026-33630. (#2)
- Bumped vite 8.1.4 → 8.1.5 (#3) and nunomaduro/collision (#1).

## v0.0.1 — Alpha (2026-07)

First public release.

- Markdown/wiki pages with a rich editor over portable Markdown source, inline Mermaid diagrams (strict security mode, no external calls), and authorization-aware `[[Page Name]]` wiki links.
- Single-file HTML artifact pages (paste or upload) rendered only from an isolated, cookieless artifact origin behind sandboxed iframes and short-lived HMAC-signed URLs; pre-save draft preview uses an authenticated, short-lived HMAC capability bound to the exact content before rendering in the same opaque no-network sandbox.
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
