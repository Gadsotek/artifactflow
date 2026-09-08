# External artifact sharing

Implemented narrow anonymous page capabilities. First released in v0.0.7.
This is not public browsing, recipient identity, or a general publishing system.

## Scope

| Mode | Contract |
| --- | --- |
| Expiring | Required expiry; may start multiple viewing sessions before it |
| One-time | No expiry; exactly one successful explicit redemption |

Both follow the latest current version of one page and are manually revocable.
No workspace, taxonomy, identity, search, source, history, provenance, editing,
MCP, realtime, or authenticated navigation is exposed. Original Office files
are never shared. Native PDF/DOCX-PDF viewing is download-equivalent even
though no separate anonymous download endpoint exists.

## Capability flow

```text
fragment share secret
  -> same-origin POST exchange
  -> pending-open credential when confirmation is required
  -> explicit open POST for every one-time link
  -> separate anonymous view cookie + per-window proof
  -> live-authorized content and short-lived artifact URL
```

The raw secret, pending credential, view credential, and signed preview are
distinct. None is the authenticated Laravel session.

The creator receives `https://app.example/external-shares/{share_uid}#secret=...`
once. The selector is a non-secret ULID; the secret is 256 random bits in
unpadded base64url. Store only a domain-separated SHA-256 hash and compare
with `hash_equals`.

Bootstrap GET never looks up or consumes the share. First-party script validates
the fragment, removes it immediately with `history.replaceState`, and sends
it only in a same-origin POST body. There is no path/query or no-JavaScript
fallback. Without JavaScript, show generic guidance without artifact metadata.

All public responses use private no-store, no-referrer, noindex/nofollow/noarchive,
nonce CSP, and no third-party resources. Never log request bodies or copy raw
URLs into email automation, analytics, events, audits, metrics, or exceptions.

## Creation and revocation

Creation validates mode/UTC expiry, locks the page then its workspace in the
established order, and rechecks creator authority, page state, live sharing
policy, format enablement, and active-share ceilings before persisting a hash.

| Caller | Required authority |
| --- | --- |
| Browser | Human with live `PageAccess::canManageAccess` |
| MCP | `mcp:share`, exact workspace scope, page ownership, live edit access, and effective `allow_editor_page_sharing` |

MCP is Editor-capped even for an underlying Admin and grants no list, revoke,
or access-management capability. Human/service-account principals follow the
same owner rule. The returned bearer URL must not enter artifacts, metadata,
prompts, traces, or logs.

Creation records `page.external_share.created` with non-secret actor/token/session
attribution, commits, then returns the URL once. Revocation locks page then
share, reauthorizes access management, terminally revokes, deletes its sessions,
and records `page.external_share.revoked`. Repeated revocation is idempotent.

## Policy and storage

| Setting/limit | Default and boundary |
| --- | --- |
| Sharing enabled | False on new and existing installations |
| Acknowledgement required | True |
| Maximum expiring lifetime | 7 days; UI permits 1..30 whole days, stored as hours with ceiling 720 |
| Active shares | At most 20 per page and 10,000 per installation |
| Retained view sessions | 100 per share by default; new issuance evicts oldest under lock |
| Pending-open lifetime | Five minutes |
| Last-viewed refresh | At most once per five minutes |

System Admin changes installation policy only after recent live 2FA, with
audit/events. Global disable blocks creation, sessions, viewer loads, and
renewal while retaining inventory.

`external_shares` stores page, copied workspace/access revision, mode/expiry,
creator, terminal state, hash, and bounded activity facts. Database checks
enforce exclusive mode/expiry combinations. Redemption cannot be reset.
`external_share_sessions` stores kind, share, credential hash, timestamps, and
pending expiry/consumption. View sessions have no independent countdown.
Hard page deletion cascades both tables.

Cookies are opaque, dedicated, HttpOnly, SameSite Strict, secure on HTTPS,
non-persistent, and narrowly path-scoped. The non-secret viewer session UID
separates permitted expiring-share windows' cookie paths.

## Exchange and explicit open

Exchange rechecks secret, policy, page, copied workspace/access revision,
share mode/state, and expiry. After successful exchange:

- acknowledgement enabled: issue a pending session and fixed safety warning;
- acknowledgement disabled, one-time: issue pending session and neutral open confirmation;
- acknowledgement disabled, expiring: issue a view session immediately.

Only then expose escaped title/live labels, never the sharer's identity.
Open POST requires pending cookie, matching CSRF, and same-origin Origin/
Sec-Fetch-Site checks. Under page/share locks it rechecks live state, consumes
pending authority, and creates the view session. A one-time winner also sets
`redeemed_at`; exactly one concurrent redemption wins.

Record first `page.external_share.opened` and one-time
`page.external_share.consumed`. Later successful loads refresh coarse
activity without generating an audit row per request. Inventory keeps
redeemed one-time sessions visible and revocable.

## Window proof and live validation

The redeeming top-level context receives a keyed domain-separated proof of
its view credential in `sessionStorage`. Never place it in URLs, cookies,
server persistence, or rendered HTML. Viewer GET is metadata-free; content
POST requires both the HttpOnly cookie and this proof.

Reloading that window works while live checks pass. An independently opened
window with only the shared cookie jar fails. Browser duplication/opener
behavior or deliberate copying can clone client-held state: this is lifecycle
friction, not DRM or proof of recipient identity.

Every content load/preview issuance checks global policy, live session, share
revocation/expiry, page existence/archival, copied workspace, and copied access
revision. PDF/XLSX/DOCX also check live format flags; DOCX requires PDF.
Moves and relevant access changes invalidate shares. New versions stay visible;
Deprecated remains viewable with a fixed warning. Historical versions do not.

Expired/revoked shares remain in authorized inventory for 90 days before
scheduled pruning. Terminal pending sessions are pruned after 24 hours.
A redeemed one-time share with a retained view session is not pruned out from
under a long-open viewer. Page deletion and explicit revoke remain effective.

## Presentation and failure

`ExternalPagePresentationRegistry` exhaustively maps every page type to a safe
presenter. A future type has no raw-byte fallback.

| Type | External presentation |
| --- | --- |
| Markdown | Sanitized rendering; private wiki links inert |
| HTML | Opaque scripts-only artifact-origin iframe with restrictive header CSP |
| Image | Normalized pixels in the scriptless artifact-origin viewer |
| XLSX | Validated typed manifest in the application-owned sandboxed grid; external links require a destination-visible second click on the app origin |
| PDF | Native PDF on the cookieless artifact origin under the PDF-only sandbox exception |
| DOCX | Independently validated PDF derivative under that same exception; external link actions stripped/rejected |

PDF responses use application-owned content type/disposition/filename,
no-store, nosniff, no CORS/cookies, app-only frame ancestors, and restrictive
PDF CSP. Native viewing permits save/print/copy and cannot be made revocable
after delivery. No original XLSX/DOCX bytes are transmitted anonymously.

Share-purpose preview URLs bind share/session, current page/version, copied
access revision, artifact origin, and expiry no later than preview TTL or the
expiring share. They contain no raw share secret and are not interchangeable
with authenticated preview purposes.

Invalid, missing, expired, redeemed, revoked, moved, archived, access-invalidated,
disabled, and rate-limited requests share one unavailable status/presentation,
without title/type/workspace/reason disclosure. Creation has actor/page rate
limits; public operations have source/selector/operation and source-wide limits.
The source-wide budget defeats selector rotation; scheduled database-counter
pruning bounds expired state.

## Residuals and required evidence

Bearer possession is not identity. Recipients can retain delivered bytes or
copy viewer proof. HTML keeps its self-navigation and browser-dependent WebRTC
residuals. A safety acknowledgement is not a browser boundary.

Maintain tests for secret leakage, unfurl safety, concurrent redemption,
cookie/window separation, uniform failures, live revocation and feature flags,
every presenter, exact MCP ownership/scope, event redaction, limits, and cleanup.
Browser evidence covers Chromium, Firefox, WebKit, and the released Safari/iOS
pass. No URL-secret fallback, reusable unlimited link, authenticated-session
reuse, or generic public presenter is supported.
