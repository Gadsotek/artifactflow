# MCP bridge maintenance

[MCP setup](mcp.md)

Claude's supported Desktop JSON path cannot supply ArtifactFlow's static
bearer token directly. The connector therefore uses `mcp-remote@0.8.3` for
Claude Desktop/Code. Codex connects directly over authenticated HTTP.

The bridge is an experimental third-party process that receives the token.
Reproducible installation does not make it trusted first-party software. Use
narrow, short-lived tokens and review every bridge upgrade.

## Installation contract

- Require Node.js 20.18.1 or newer, npm, and npx.
- Verify the committed lock's reviewed SHA-256 fingerprint.
- Install the complete integrity lock with `npm ci --engine-strict --ignore-scripts`.
- Use a separate user-data directory per fingerprint, so a failed upgrade
  cannot destroy the version referenced by existing client configs.
- Fix that directory as the generated config's working directory and run the
  exact version with `npx --offline --no-install` and a dedicated empty cache.
- A missing local package must fail closed, without searching project packages,
  mutable latest versions, or an independently cached dependency graph.
- Store configs only in selected user-level targets, back them up before
  merging, and use mode `0600`. Never write tokens to repository configs.

`MCP_BRIDGE_HOME` overrides the versioned user-data root when needed.
The connector respects `CLAUDE_CONFIG_DIR`, `CODEX_HOME`, conventional sibling
configs/homes, and existing Codex profile overlays. Targets are never selected
silently.

## Upgrade and verification

Review the exact version and package delta, regenerate the lock, and update
the expected integrity in `scripts/verify-mcp-remote.mjs`. Every registry
package requires SHA-512 integrity. The nested graph participates in audit and
dependency update coverage.

Nightly checks perform a clean locked install, verify registry integrity,
audit dependencies, make a real authenticated loopback initialize/tools-list
exchange, and prove the missing-package failure. Preserve all these checks.

Remove the bridge when the supported client path can provide authorization
natively or the project adopts a compatible first-party authorization flow.
