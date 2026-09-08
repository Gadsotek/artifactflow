# Frequently asked questions

## What does ArtifactFlow store?

Markdown with Mermaid, self-contained HTML, normalized PNG/JPEG, and default-off
PDF, XLSX, and DOCX. PDF uses native text, XLSX uses a typed visible-cell manifest,
and DOCX uses an independently validated PDF derivative. No OCR or formula
recalculation. Document formats need isolated processors; DOCX also needs PDF.

## Does it generate content or import my chats?

No. Paste, upload, or use an authorized MCP client. Keep using the AI tools you
choose. ArtifactFlow requires no AI API key or model subscription itself.

## How do teams and AI share the work?

Pages live in personal/shared workspaces with Reader, Editor, or Admin access
and page overrides. Shared workspaces may have three levels with downward
membership inheritance. Search and navigation filter by live access.

MCP tokens have operation and exact workspace scopes, capped at Editor.
They can search/read, create/update, upload supported files, organize metadata,
and create narrow owned-page shares with the required scopes. MCP cannot
administer workspaces or bypass permissions.

## Is version history unlimited?

No. Content changes append versions; configured retention prunes the oldest.
The default cap is 200. Restore appends a new version. Metadata has its own
revision and is not restored as a historical snapshot. Source diffs exist;
a visual diff UI and simultaneous editing do not.

## Can I share with someone without an account?

Yes, when enabled. Create a required-expiry reusable link or a one-time link
consumed by explicit redemption. It presents the current version of one page,
without workspace browsing, search, history, or editing. Revocation blocks
future loads; recipients can keep delivered bytes. PDF/DOCX-PDF viewing is
download-equivalent. No anonymous original Office download is added.

## Can I run production with the bundled Compose file?

The bundled stack is local-only. Production needs separate HTTPS app/artifact
hostnames, verified database TLS, private shared storage, restricted database
roles, separate limiter stores, mail, and independent secrets. The artifact
host must receive no app cookies. Cookies ignore ports.

Follow [production operations](https://github.com/Gadsotek/artifactflow/blob/main/docs/operations/production.md).
Production currently supports dedicated database rate-limit stores only;
ordinary application cache options have a different contract.

## Is the sandbox a perfect network seal?

No. Origin isolation, an opaque iframe, and header CSP protect app authority.
Server rewriting and an early guard close maintained nested-frame/API cases.
HTML self-navigation can send embedded or user-entered data externally, and
WebRTC blocking is browser-dependent. Never enter secrets into an artifact.

## Does MCP solve prompt injection?

No. Content is marked as untrusted, but an AI can still follow it. The server
checks every operation's scope, authority, revisions, and budgets. Human
approval behavior belongs to the client. Use the smallest useful token scope.

## Has it been audited?

The project has internal AI-assisted adversarial review and automated checks.
The full browser suite runs on Chromium; artifact-security tests also run on
Firefox/WebKit. Released Safari/iOS has a separate manual pass. There has been
no independent third-party security audit.

## What license applies?

[AGPL-3.0-or-later](https://github.com/Gadsotek/artifactflow/blob/main/LICENSE),
with a [commercial option](https://github.com/Gadsotek/artifactflow/blob/main/COMMERCIAL.md).
Contributions require DCO sign-off and the one-time CLA. See
[Contributing](https://github.com/Gadsotek/artifactflow/blob/main/CONTRIBUTING.md).
