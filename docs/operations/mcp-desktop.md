# Claude Desktop extension

[MCP setup](mcp.md) · [Bridge maintenance](mcp-bridge.md)

The ArtifactFlow desktop extension connects Claude Desktop on macOS or Windows
to your ArtifactFlow server using your personal MCP token. Claude Desktop runs
the packaged bridge with its built-in Node.js. End users do not install Node.js,
npm, Python, or a command-line connector.

## Install and connect

1. Get the `artifactflow-1.0.2.mcpb` file from your installation administrator.
2. In ArtifactFlow, open **AI connections** and create a personal token with the
   operations and workspaces you need. Token creation requires two-factor
   authentication. Copy the token when it is shown.
3. Open the `.mcpb` file with Claude Desktop and choose **Install**. Alternatively,
   use **Settings > Extensions > Advanced settings > Install Extension**.
4. Enter your **ArtifactFlow app URL**, such as `https://app.example.com`, and
   your **Personal MCP token**, starting with `af_mcp_`. Do not add `Bearer`.
5. Save the settings, enable the extension, and start a new conversation. Ask
   Claude to list the ArtifactFlow tools or search a page you can access.

The token is a sensitive extension setting managed by Claude Desktop. It is
supplied to the bridge through its environment, never embedded in the package
or placed in command-line arguments. Every user supplies their own token;
the same package can be distributed to the whole team. Token scopes and live
ArtifactFlow permissions continue to govern every operation. When a token
expires or is revoked, replace it in the extension's settings.

Install a current Claude Desktop release and keep **Use built-in Node.js for
MCP** enabled if your client exposes that setting. The packaged bridge requires
Node.js 20.18.1 or newer. Organization policy may require your administrator to
allow this custom extension before installation.

## Network and authentication

Requests originate on the user's computer, so its normal LAN/VPN access to the
server applies. HTTPS and valid certificates are required for remote servers.
Only explicit `localhost`, `127.0.0.1`, or `[::1]` addresses may use plaintext
HTTP for local development. Use the app origin or its `/mcp` endpoint; the
artifact preview origin is not an MCP endpoint.

If the web interface is behind HTTP Basic authentication, exempt the exact
`/mcp` endpoint from that proxy layer and forward `Authorization` unchanged.
ArtifactFlow still requires the personal bearer token. Basic credentials do
not belong in the extension URL or token setting.

This local extension is separate from Claude's cloud-hosted custom connectors.
It does not add OAuth, disable certificate checks, install an AI model, or
change ArtifactFlow permissions.

## Privacy Policy

Read the [ArtifactFlow Desktop Extension Privacy Policy](mcp-desktop-privacy.md)
before connecting. A copy is included in the bundle as `PRIVACY.md`. The
connector forwards requested tool inputs and server results to and from Claude;
results can contain private artifacts. Your ArtifactFlow operator and your
Claude account's policies govern their respective data storage and retention.
Each user supplies a personal token; revoking it stops future access but does
not erase content already returned to a conversation.

## Troubleshooting

- **Invalid URL or token:** paste the HTTPS app URL and the complete personal
  token. URLs containing credentials, query strings, fragments, or other paths
  are rejected. Do not prefix the token with `Bearer` or include whitespace.
- **Connection failed:** check VPN/network access, the server certificate,
  token expiration/revocation, and the proxy exemption for `/mcp`. Finish any
  pending ArtifactFlow installation or migrations before reconnecting.
- **Bundled bridge missing:** reinstall the administrator-provided package.
  The extension never downloads missing dependencies at launch.
- **No tools or duplicate connection:** enable the extension and remove or
  disable the older manually configured ArtifactFlow connection if present.

Bridge diagnostics are deliberately suppressed because remote error bodies can
contain private content. The launcher reports fixed configuration/connection
errors; do not enable upstream debug logging with real tokens or artifacts.

## Build for distribution

An administrator or release maintainer builds the package once on macOS or
Linux. The build machine requires Node.js 20.18.1 or newer, npm, `zip`, and
`unzip`. These are build tools, not end-user installation requirements.

```sh
node scripts/build-mcp-desktop.mjs
```

The output is `storage/app/mcp-desktop/artifactflow-1.0.2.mcpb`; the build prints
its SHA-256 checksum. Distribute that file through your approved internal
software channel. Generated bundles and dependency directories stay out of Git.
The package is unsigned; it is not an Anthropic directory listing.

To choose a different output file:

```sh
node scripts/build-mcp-desktop.mjs --output /tmp/artifactflow-review.mcpb
```

Existing output files are never overwritten. Each build verifies the existing
reviewed bridge fingerprint, runs launcher regressions, installs the complete
lock with lifecycle scripts disabled in a fresh temporary directory, and
packages only the launcher, manifest, logo assets, instructions, privacy notice, licenses, and installed
locked dependencies. It then extracts the actual archive and verifies an
authenticated initialize/tools-list/tool-call exchange plus rejected tokens
without Node/npm/Python on `PATH`. No real credentials or application database
are used. Build and smoke staging directories are left in the operating system
temporary directory for normal temporary-file cleanup.

Before distributing a release, test installation and a narrowly scoped real
connection in Claude Desktop on the target macOS/Windows versions, including
token replacement and revocation. The automated transport smoke is not proof
of the Desktop installer UI or organization policy. Keep the dependency
review, audit, and license gates in [bridge maintenance](mcp-bridge.md).

Format and runtime references:
[MCP Bundles](https://github.com/modelcontextprotocol/mcpb) and
[Claude Desktop extension installation](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop).
