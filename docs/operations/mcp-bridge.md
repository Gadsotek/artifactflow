# MCP bridge maintenance

[MCP setup](mcp.md)

Claude's supported Desktop JSON path cannot supply ArtifactFlow's static
bearer token directly. The connector therefore uses `mcp-remote@0.14.2` for
all supported local clients, including Codex releases that need the stdio bridge.

The [Claude Desktop extension](mcp-desktop.md) packages the same reviewed lock
for use with Claude Desktop's built-in Node.js. Its end users do not install
Node.js or npm. The shell connector below remains a separate compatibility path.

The bridge is an experimental third-party process that receives the token.
Reproducible installation does not make it trusted first-party software. Use
narrow, short-lived tokens and review every bridge upgrade.

## Installation contract

- Require Node.js 20.18.1 or newer and npm.
- Verify the committed lock's reviewed SHA-256 fingerprint.
- Install the complete integrity lock with `npm ci --engine-strict --ignore-scripts`.
- Use a separate user-data directory per fingerprint, so a failed upgrade
  cannot destroy the version referenced by existing client configs.
- Run the installed entrypoint through an absolute Node.js path. Neither
  client-specific working-directory support nor `npx` resolution is required.
- A missing local package must fail closed, without searching project packages,
  mutable latest versions, or an independently cached dependency graph.
- Store configs only in selected user-level targets, back them up before
  merging, and use mode `0600`. Never write tokens to repository configs.

`MCP_BRIDGE_HOME` overrides the versioned user-data root when needed.
The connector respects `CLAUDE_CONFIG_DIR`, `CODEX_HOME`, conventional sibling
configs/homes, and existing Codex profile overlays. Targets are never selected
silently.

## Upgrade and verification

Dependabot updates the package and lock but cannot approve the new executable
that receives bearer tokens. Its PR will intentionally fail the integrity gate
until the upstream delta and published tarball have been reviewed. Do not
remove the fingerprint check or automatically approve whatever the lock contains.

After reviewing the exact version, full lock delta, and published SHA-512 tarball
integrity, synchronize the connector, verifier, smoke, and regression fixture pins
in one command, substituting the values from that review:

```sh
node scripts/update-mcp-remote-pins.mjs \
  --reviewed-version VERSION \
  --reviewed-lock-sha256 LOCK_SHA256 \
  --reviewed-integrity TARBALL_SHA512_INTEGRITY
node --test scripts/update-mcp-remote-pins.test.mjs
node scripts/verify-mcp-remote.mjs
make test TEST_FILTER=ConnectMcp
```

The helper checks the explicit values against the exact candidate bytes and
validates every target before writing. It refuses unexpected source drift,
changes to the Node.js floor or qs override, and non-registry or unhashed packages.
It does not fetch, install, commit, push, or replace the human review. Record the
review here, run a clean locked install and the authenticated smoke below, then
run the repository's full required gates before committing.

```sh
npm ci --engine-strict --ignore-scripts --prefix scripts/mcp-remote-bridge
node scripts/verify-mcp-remote.mjs --installed
npm audit --package-lock-only --prefix scripts/mcp-remote-bridge --audit-level=moderate
MCP_BRIDGE_SMOKE_CWD="$(mktemp -d)" node scripts/smoke-mcp-remote.mjs
```

Every registry package requires SHA-512 integrity. Nightly checks also make a
real authenticated loopback initialize/tools-list exchange and prove the
missing-package failure. Preserve all these checks. Keep dependency PRs current
with main so they include shared test fixes; retrying an old failed run still
checks its old commit.

Remove the bridge when the supported client path can provide authorization
natively or the project adopts a compatible first-party authorization flow.

## Desktop bundle verification

```sh
node scripts/build-mcp-desktop.mjs --output /tmp/artifactflow-desktop-review.mcpb
```

The builder runs launcher regressions and this document's independent bridge
fingerprint verifier before installing the lock in fresh staging. Lifecycle
scripts and binary links are disabled. It preserves dependency licenses,
records the lock and launcher hashes in `BUILD.json`, and uses a fixed file
timestamp plus sorted ZIP entries. Existing packages are never overwritten.
No new dependency graph or automatically accepted integrity pin is introduced.

The extracted-archive smoke exercises authenticated initialization, tool
listing and reading, 401/403 failures, and diagnostic privacy from a different
working directory with no external runtimes on `PATH`. To repeat it separately:

```sh
node scripts/smoke-mcp-desktop.mjs /tmp/artifactflow-desktop-review.mcpb
```

Repeat the macOS and Windows Claude Desktop installation/connection checks
when releasing the extension, including its sensitive token setting and the
built-in runtime. Update the extension version when its distributed contents
change, including a reviewed bridge upgrade. The bundle does not install
updates itself. Preserve the original bridge smoke and all existing gates.

## Reviewed version

The 0.14.2 review covers upstream commit
`8ba22bdb4e73b818abf22b5e0c8fb5d96e90203b` and the
[0.13.5 to 0.14.2 delta](https://github.com/punkpeye/mcp-remote/compare/v0.13.5...v0.14.2):
OAuth callback issuer forwarding, optional explicit client-credentials token
endpoints, and renewal through the configured transport fetch with stored-token
fallback. ArtifactFlow supplies a static bearer header and enables neither
client credentials nor an explicit token endpoint. It keeps the default legacy
protocol mode and does not enable protocol auto-discovery.

The published tarball's SHA-512 integrity matches the lock. The separately
locked runtime dependencies and Node.js floor are unchanged. The installed
smoke verifies the bridge version observed by the server, authenticated legacy
initialize/tools-list traffic without auto-discovery, and absence of the token
from stdout/stderr.
