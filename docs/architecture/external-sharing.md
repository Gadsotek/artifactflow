# External Artifact Sharing Architecture Decision

Status: Accepted
Date: 2026-07-29
Scope: Anonymous expiring and one-time page-share capabilities

## Context

ArtifactFlow needs a deliberately narrow way to share the latest artifact with
a person who has no installation account. A human page access manager may
create a share in the application. An explicitly scoped MCP principal may
create one only for an in-scope page it owns and can still edit while that
workspace allows Editors and page owners to share pages. This is not public
publishing: a recipient receives a high-entropy bearer capability for one page
and no catalog, workspace, identity, search, source, history, editing, MCP, or
download authority.

The product contract defines exactly two mutually exclusive modes:

- an expiring link may start multiple viewing sessions until its required
  expiry;
- a one-time link has no expiry and may start exactly one viewing session.

Every share follows the page's latest current version. It is manually
revocable, fails closed after page archival, deletion, workspace movement, or a
relevant access-boundary change, and uses the existing safe presentation
boundary for each page type.

## Decision

Use four distinct capabilities:

```text
raw share secret
    fragment-only, presented once, exchanged once per browser bootstrap
        ↓
pending-open session
    server-stored, short-lived, no artifact authority
        ↓ explicit same-origin open
external-view session
    HttpOnly browser-session cookie, server-stored without an arbitrary timeout
        + per-window proof
    sessionStorage only, issued to the redeeming top-level browser context
        ↓
short-lived artifact-preview URL
    existing artifact-origin sandbox, additionally bound to the share
```

The raw share secret is never an authenticated application session and never
appears in an HTTP request target. The pending-open and external-view
credentials are independent, opaque, narrowly scoped cookies whose hashes are
stored server-side. Artifact content requires both the external-view cookie and
the separate per-window proof. This prevents an independently opened anonymous
window that shares only the browser's cookie jar from reusing the winning
one-time viewing session. It does not stop an authorized recipient from
deliberately cloning client-held state.

## Capability URL and bootstrap

The sender receives this shape exactly once:

```text
https://app.example/external-shares/{share_uid}#secret={base64url-secret}
```

The selector is a non-secret ULID. The secret contains 256 cryptographically
random bits encoded as unpadded base64url. Persistence stores only a
domain-separated SHA-256 hash of the decoded secret. Hash comparison uses
`hash_equals`.

`GET /external-shares/{share_uid}` returns the same minimal, first-party
bootstrap document without looking up or disclosing share state. A small
nonce-authorized first-party script:

1. reads and validates the fragment grammar;
2. immediately removes the fragment with `history.replaceState`;
3. sends the selector and secret in the body of a same-origin `POST` exchange;
4. replaces the bootstrap with the interstitial, neutral confirmation, viewer,
   or uniform unavailable state returned after validation.

JavaScript is required for the external share flow. There is no query-string,
path-token, form fallback, or server-side fragment workaround because each
would put the reusable bearer secret into request URLs, proxy logs, referrers,
or HTML. With JavaScript unavailable, the bootstrap shows a generic message
without artifact metadata.

The bootstrap, exchange, open, viewer, and unavailable responses use:

- `Cache-Control: no-store, private`;
- `Referrer-Policy: no-referrer`;
- `X-Robots-Tag: noindex, nofollow, noarchive`;
- a restrictive nonce-based application CSP with no third-party resources;
- no private artifact metadata before successful capability exchange.

`GET /external-shares/{share_uid}/sessions/{view_session_uid}/viewer` is a
metadata-free application shell. The non-secret view-session UID scopes the
HttpOnly cookie path so separate permitted windows for one expiring share keep
independent credentials; the credential and per-window proof remain required.
After redemption, the bootstrap stores a keyed, domain-separated proof of the
external-view credential in that top-level browsing context's
`sessionStorage`, then navigates to the shell. The proof is never put in a URL,
cookie, server-side persistence, or rendered HTML. The shell retrieves artifact
content through a same-origin `POST` that requires both the HttpOnly view
cookie and the per-window proof. A reload in the winning context retains the
proof; an independently opened anonymous window receives only the uniform
unavailable surface even when its browser profile shares the view cookie.
Browsers can copy `sessionStorage` into an opener-created auxiliary context,
and an authorized recipient can copy the proof deliberately; this mechanism is
window-lifecycle friction, not recipient identity or DRM.

The complete URL is not emitted by ArtifactFlow email, analytics, events,
audits, exceptions, metrics, or logs. Operators must still configure edge and
application logging not to record request bodies.

## Persistence

### `external_shares`

- `uid` ULID primary key and non-secret selector;
- `page_uid` foreign key with cascade on hard deletion;
- `secret_hash` fixed lowercase SHA-256;
- `mode` (`expires_at` or `one_time`);
- required `expires_at` only for expiring mode;
- copied `page_workspace_uid` and `page_access_revision` at creation;
- creator UID and creation timestamp;
- nullable redemption timestamp;
- nullable revocation timestamp and revoker UID;
- bounded first-view/session-count facts plus a coarsely refreshed last successful viewer-activity timestamp.

Database checks enforce the exclusive mode/expiry combinations. A one-time row
can move from active to redeemed once and can never be reset. An expiring row
can move from active to expired only through time. Revocation is terminal.

### `external_share_sessions`

- `uid` ULID primary key;
- share foreign key with cascade;
- `credential_hash` fixed lowercase SHA-256;
- `kind` (`pending_open` or `view`);
- hard `expires_at` for pending-open sessions and no independent expiry for
  window-lived view sessions;
- nullable consumed timestamp for a pending credential;
- creation timestamp.

Raw session credentials are never persisted. Terminal or expired pending-open
session rows are short-lived operational records and are pruned after 24
hours. Each share retains at most 100 window-lived view sessions by default;
issuing another evicts the oldest session under the already-locked share, and
operators may lower or raise that positive ceiling. The viewer cookie uses a
dedicated name, `HttpOnly`, `Secure` in HTTPS
deployments, `SameSite=Strict`, no persistent expiry, and a path limited to the
selected share surface. It is not read by the normal Laravel authentication
guard.

## Installation policy and limits

The installation settings record gains:

- `external_sharing_enabled`, default `false` on existing and new
  installations;
- `external_share_acknowledgement_required`, default `true`;
- `external_share_max_expiry_hours`, stored internally as 168 hours (7 days) by
  default, with a hard ceiling of 720 hours (30 days). The System Admin form
  exposes this as whole days from 1 through 30 and converts it to hours at the
  request boundary.

Enabling or changing the policy requires System Admin authority and recent
live two-factor confirmation, and records non-secret events and audit entries. The
global disable switch blocks creation and new sessions without deleting
inventory.

Operator configuration bounds active shares to 20 per page and 10,000 per
installation. These are hard ceilings rather than recipient-visible product
settings. Creation locks the page before both counts are rechecked. Rate limits
apply per actor/page for creation and per source/selector bucket for exchange
and open; limit responses remain indistinguishable from other unavailable
states on the public surface.

The creation form mirrors the configured expiry ceiling through the native
date-time control's maximum so recipients cannot select a known-invalid
expiry. The server independently validates the same limit.

## Creation and revocation

Creation and revocation are application handlers, not controller or model
workflows.

Creation:

1. validates typed mode and normalized UTC expiry at the HTTP or MCP boundary;
2. starts a transaction, locks the page, then locks and reads its workspace
   sharing policy in the established page-to-workspace order so disabling
   editor sharing cannot commit between creator authorization and share
   creation;
3. rechecks the creator contract and page state: the browser path requires a
   human actor with live `PageAccess::canManageAccess`, while the MCP path
   requires `mcp:share`, token-workspace reach, live edit authority, and page
   ownership by the MCP principal, plus the workspace's live
   `allow_editor_page_sharing` policy;
4. rechecks installation policy and active-share bounds;
5. generates the share secret and persists only its hash;
6. records `page.external_share.created` in both the durable event journal and
   user-facing audit trail, including non-secret MCP token/session attribution
   when applicable;
7. commits before returning the raw URL once.

Revocation locks the page and share in that order, rechecks manage-access
authority, marks the row terminal, deletes its pending/view sessions, and
records `page.external_share.revoked`. Repeated revocation is idempotent.

MCP share creation is deliberately narrower than browser share management.
`mcp:share` grants no list, revoke, access-management, or administrator
capability. Human and service-account principals may use it only for a page
they own, can still edit, and can reach through the token's workspace ceiling,
and only while the workspace allows Editors and page owners to share pages.
Because MCP authority is always de-elevated to Editor, even an underlying
workspace administrator cannot bypass that switch through the tool. The
`create_external_share` result returns the bearer URL once alongside non-secret
share metadata. MCP clients must treat that URL as a secret and must not copy
it into artifacts, metadata, prompts, traces, or logs.

## Exchange, acknowledgement, and open

Exchange validates installation policy, selector/secret, page state, copied
workspace UID, copied access revision, mode state, and expiry. Every invalid
case returns the same unavailable representation.

After successful exchange:

- acknowledgement-required installations issue a five-minute pending-open
  session and show the fixed warning;
- acknowledgement-disabled one-time shares issue the same pending-open session
  and show a neutral **Open artifact** confirmation;
- acknowledgement-disabled expiring shares immediately create a view session
  and enter the viewer.

The title and live/latest label appear only after successful exchange, escaped
as untrusted text. The sharer's display name is never exposed.

The open `POST` requires the pending cookie, a matching same-origin CSRF token,
and Origin/Sec-Fetch-Site validation. It consumes the pending session.

For a one-time share, the transaction locks the page and share in the standard
order, rechecks all state, writes `redeemed_at`, and creates the winning view
session before commit. Exactly one concurrent transaction can win. For an
expiring share, the same transaction creates a view session only if expiry is
still in the future.

View sessions have no independent countdown. Viewer content and external
preview-URL renewal require the per-window proof retained by the redeeming
top-level browser context. Reloading that context continues to work for as long
as it remains open and the live share/page checks pass. Closing it normally
removes the practical viewing authority because an independently opened window
does not have its `sessionStorage` proof. This assumes the recipient did not
deliberately clone or copy that client-held proof. A still-active expiring link
may start a new session through a fresh secret exchange, but every such session
remains bounded by the share's own expiry.

The first successful session records `page.external_share.opened`. A one-time
winner also records `page.external_share.consumed`. Session issuance updates the
bounded first-view and count fields. Successful viewer-content and preview-URL
resolutions refresh the last-viewed field at most once per five minutes, without
creating an unbounded event or audit row per request. Redeemed one-time inventory
rows show when a retained window-lived view session is still open and can be closed
through the existing revoke action.

## Live revalidation and lifecycle

Every viewer load and artifact-preview URL issuance rechecks:

- installation sharing is enabled;
- the view session is live;
- the share is not revoked;
- an expiring share is not expired;
- the page exists and is not archived;
- the page still belongs to the copied workspace;
- the page access revision still equals the copied revision.

A new current version does not increment this sharing boundary and is resolved
when the viewing session starts or the viewer refreshes. Historical versions
are unavailable. A workspace move or relevant access change invalidates the
share. Deprecation does not invalidate it; the viewer shows a fixed
application-owned deprecated warning. Hard page deletion cascades share and
session rows.

Revocation or global disable prevents new loads and short-lived preview URL
renewal. It cannot erase bytes already delivered to a browser.

Expired and revoked share rows remain in the authorized inventory for 90 days,
then a scheduled handler prunes them. A redeemed one-time share that still owns
its window-lived view-session row is retained so background cleanup cannot
silently break a long-open viewer. It remains manually revocable and is
removed by page deletion. Domain-event and audit retention remain governed by
their own operator policy.

## Presentation registry

External sharing targets `Page`, not a content type. The application uses an
exhaustive `ExternalPagePresentationRegistry` keyed by `PageType`. An
architecture test enumerates every page type and fails unless it has an
explicit safe presenter.

- Markdown uses the existing sanitized renderer with wiki links rendered as
  inert text; it does not run an authorization-sensitive relative-page lookup
  on the public surface.
- HTML uses the isolated artifact origin in an opaque
  `sandbox="allow-scripts"` iframe under the existing restrictive header CSP.
- Normalized raster images use the fixed scriptless artifact-origin viewer and
  `sandbox=""`.
- A future type cannot become shareable until its normal safe preview strategy
  is explicitly registered. There is no raw-byte fallback.

The viewer may mint only share-purpose artifact-preview URLs. Those URLs bind
the share UID, view-session UID, current page/version UID, expiry, copied
access revision, and configured artifact origin. They are not interchangeable
with authenticated preview URLs, contain no raw share secret, and expire no
later than their short preview TTL or the expiring share.

## Uniform failure and disclosure boundary

Invalid selector, malformed or wrong secret, disabled policy, expired,
redeemed, revoked, archived, deleted, moved, access-invalidated, missing
session, and rate-limited requests use the same public unavailable page and
status behavior. They disclose no title, page type, workspace, lifecycle
reason, or whether a share existed.

The external surface exposes only:

- escaped page title after successful capability exchange;
- live/latest and deprecated application-owned labels;
- expiry for an expiring share;
- the safely presented current artifact.

The viewer uses the application's visual language and supports local
light/dark/system theme preference without contacting a third party. Absolute
timestamps are transported as ISO instants and formatted in the recipient's
browser time zone; the server time zone is never presented as if it were local.

It never exposes workspace membership, owner or sharer identity, hierarchy,
taxonomy, search, source, history, provenance, internal links, realtime
presence, MCP, downloads, or authenticated navigation.

## Rejected alternatives

- **Secret in a path or query string:** leaks into request logs, browser
  history, referrers, and common analytics.
- **Server-rendered no-JavaScript fragment flow:** URL fragments are not sent
  to servers; every workaround reintroduces URL leakage.
- **Reuse Laravel's authenticated session:** couples anonymous capability
  authority to installation login state and risks confused-deputy behavior.
- **Signed stateless view cookie:** cannot atomically revoke sessions or
  distinguish a consumed one-time winner without additional state.
- **Consume one-time links on GET:** mail scanners, unfurlers, prefetchers, and
  accessibility tooling would spend the capability.
- **Combined one-time plus expiry mode:** contradicts the deliberately simple,
  exclusive product contract.
- **Reusable link without an expiry:** creates a durable public capability and
  contradicts the bounded external-sharing product and threat model. Use a
  one-time link when no calendar expiry is wanted; it remains usable until
  redeemed or revoked but cannot start multiple viewing sessions.
- **Pinned-version shares:** turns a live page share into a historical-content
  distribution feature and complicates revocation and inventory semantics.
- **Direct artifact-origin bearer links:** bypass the app-owned interstitial
  and cannot safely coordinate one-time redemption or a multi-request viewer.
- **Generic raw-content presenter:** would eventually serve a future parser or
  active format through an undefined trust boundary.

## Required proof before release

Implementation remains disabled until focused PHP and cross-browser
`@artifact-security` tests prove:

- secret one-time display, hash-only persistence, fragment removal, and absence
  from logs, referrers, DOM, cookies, events, audits, and artifact URLs;
- transaction-time creator authority, page-to-workspace policy locking,
  concurrent editor-sharing disablement, and active-share limit enforcement;
- dedicated `mcp:share` enforcement, token-workspace narrowing, owned-editable
  page and live workspace editor-sharing restrictions for human and
  service-account principals, and one-time-only return of the bearer URL
  without event/audit leakage;
- explicit-open one-time consumption with exactly one concurrent winner;
- denial of content and preview renewal in an independently opened second
  window that has the shared browser cookie but not the redeeming window's
  proof, without claiming protection from deliberate proof copying;
- expiry, revocation, archive, deletion, move, and access-revision failure;
- same-window reload after a long elapsed interval, with no arbitrary viewing
  countdown and no ability to redeem the original one-time link again;
- latest-version resolution with no history/source/catalog disclosure;
- Markdown link non-disclosure, HTML opaque sandboxing, and image scriptless
  sandboxing in Chromium, Firefox, and WebKit;
- identical behavior whether or not the browser also has an authenticated
  ArtifactFlow session.

The release also requires the manual Safari/iOS artifact-security pass
documented in the operations guide.
