# Limits and configuration

[Operations](../OPERATIONS.md)

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
| `IMAGE_PARSER_SOCKET_PATH` | empty | Optional app-runtime-only Unix socket for directional local transport. When set, cURL connects through this socket while `IMAGE_PARSER_URL` supplies the HTTP origin/Host value. Never mount it into artifact-host, worker, or scheduler roles. |
| `IMAGE_PARSER_CONNECT_TIMEOUT_SECONDS` | 2 seconds | App-to-parser connection timeout (hard-capped at 10 seconds) |
| `IMAGE_PARSER_TIMEOUT_SECONDS` | 12 seconds | Whole normalization timeout (hard-capped at 30 seconds) |
| `IMAGE_PARSER_MAX_CLOCK_SKEW_SECONDS` | 120 seconds | Parser request timestamp tolerance (hard-capped at 300 seconds). Keep host clocks synchronized; authenticated skew failures are recorded as `image_parser.request_failed` with reason `clock_skew`. |
| `IMAGE_NORMALIZATION_USER_PIXEL_BUDGET_PER_MINUTE` | 64 Mi pixels | Per-principal decoded-pixel budget; must allow one maximum upload and cannot exceed 64 Mi pixels |
| `IMAGE_NORMALIZATION_INSTALLATION_PIXEL_BUDGET_PER_MINUTE` | 256 Mi pixels | Shared installation decoded-pixel budget; must be at least the principal budget and cannot exceed 256 Mi pixels |
| `IMAGE_NORMALIZATION_USER_WORK_BUDGET_PER_MINUTE` | 64 Mi work units | Per-principal non-pixel work budget. Input bytes count once, PNG ancillary/JPEG header metadata bytes count again, and every parsed chunk/marker costs 1 Ki work units. |
| `IMAGE_NORMALIZATION_INSTALLATION_WORK_BUDGET_PER_MINUTE` | 256 Mi work units | Installation-wide non-pixel work budget; must be at least the principal budget and cannot exceed 256 Mi work units. |
| `PDF_PROCESSOR_ENABLED` | `false` | Default-off gate for PDF web/MCP create, replace, restore, reprocess, native preview/download, and MCP PDF read/search. Existing installations remain compatible without a processor. Production accepts `true` only with a valid app-runtime processor origin, optional absolute Unix socket, dedicated secret, and bounded timeouts. The artifact host may receive `true` for presentation but no endpoint/secret; worker and scheduler roles must keep `false`. Authorized retained PDFs remain visible in normal catalog/search because this is a processing/delivery switch, not access revocation. |
| `PDF_PROCESSOR_URL` | empty | App-runtime-only processor origin used by cURL. Enabling PDF requires an explicit value. Local Compose supplies `http://localhost` while cURL uses the mounted Unix socket; production must use HTTPS when no Unix socket is configured, and the value must never be public or supplied to a non-app runtime role. |
| `PDF_PROCESSOR_SOCKET_PATH` | empty | Optional app-runtime-only Unix socket for directional local transport. Local Compose uses it and gives the processor no Docker network; plain HTTP is accepted in production only when this path is set. |
| `PDF_PROCESSOR_SHARED_SECRET` | empty | App-runtime-only HMAC secret shared with the PDF processor. Use at least 32 non-placeholder bytes and never reuse `APP_KEY`, the artifact signing key, or the image-parser secret. |
| `PDF_PROCESSOR_CONNECT_TIMEOUT_SECONDS` | 2 seconds | App-to-processor connection timeout (hard-capped at 60 seconds). |
| `PDF_PROCESSOR_TIMEOUT_SECONDS` | 15 seconds | Whole app-to-processor request timeout (hard-capped at 60 seconds); the native engine has a shorter internal deadline. |
| `XLSX_PROCESSOR_ENABLED` | `false` | Default-off gate for XLSX create, replace, restore, reprocess, typed preview, original download, external presentation, and MCP read/search. The artifact host may receive `true` for presentation only; workers and schedulers must keep `false`. |
| `XLSX_PROCESSOR_URL` | empty | App-runtime-only pure HTTP/HTTPS origin. Local socket deployments use `http://localhost`; socketless production requires a private HTTPS proxy that forwards only to the processor socket. |
| `XLSX_PROCESSOR_SOCKET_PATH` | empty | Optional app-runtime-only absolute Unix socket. Local Compose uses it and runs the processor with no network. |
| `XLSX_PROCESSOR_SHARED_SECRET` | empty | Dedicated app/processor HMAC secret of at least 32 non-placeholder bytes; canonical `base64:` values are decoded by both Laravel and the processor, and the secret must differ from every application and parser/processor secret. |
| `XLSX_PROCESSOR_CONNECT_TIMEOUT_SECONDS` | 2 seconds | App-to-processor connection timeout (1 through 60 seconds). |
| `XLSX_PROCESSOR_TIMEOUT_SECONDS` | 15 seconds | Whole XLSX projection request timeout (1 through 60 seconds); processor work has its own bounded profile. |
| `DOCX_PROCESSOR_ENABLED` | `false` | Default-off gate for DOCX create, replace, restore, reprocess, derived-PDF preview, original download, external presentation, and MCP read/search. Requires PDF processing and presentation to be enabled. |
| `DOCX_PROCESSOR_URL` | empty | App-runtime-only pure HTTP/HTTPS origin. Local socket deployments use `http://localhost`; socketless production requires a private HTTPS proxy that forwards only to the processor socket. |
| `DOCX_PROCESSOR_SOCKET_PATH` | empty | Optional app-runtime-only absolute Unix socket. Local Compose uses it and runs LibreOffice with no network. |
| `DOCX_PROCESSOR_SHARED_SECRET` | empty | Dedicated app/processor HMAC secret of at least 32 non-placeholder bytes; it must differ from every application and parser/processor secret. |
| `DOCX_PROCESSOR_CONNECT_TIMEOUT_SECONDS` | 2 seconds | App-to-processor connection timeout (1 through 60 seconds). |
| `DOCX_PROCESSOR_TIMEOUT_SECONDS` | 35 seconds | Whole DOCX conversion request timeout (1 through 60 seconds), above the converter's 30-second internal deadline. |
| `ARTIFACT_MAX_BYTES` | 10 MiB | Shared single-blob read boundary. It caps accepted XLSX/DOCX originals, XLSX manifests, DOCX PDF previews, and signed normalized-image output, and must be at least every Markdown/HTML/image upload limit in production. The hard application ceiling is 64 MiB. |
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
