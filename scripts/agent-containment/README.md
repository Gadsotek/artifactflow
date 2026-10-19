# Agent filesystem containment

ArtifactFlow configures native filesystem controls for Codex and Claude Code.
The shared version-8 hooks remain defense in depth. These project-specific
adapters, configurations, and conformance tests are outside the shared byte
contract; changing them does not silently change another repository's policy.

## Supported clients and scope

Use Codex with native permission-profile support (0.138.0 or newer) and Claude
Code 2.1.295 or newer. Re-run native checks after client upgrades. This slice
targets local development on macOS. Other platforms need their own passing
native receipts before relying on containment; in particular, Claude's native
filesystem pattern support differs across operating systems.

The policies allow normal project source editing, dependency-source inspection,
and the toolchain/runtime/temp access each client requires. They deny unrelated
personal files and sibling checkouts by default. Runtime, skills/plugins, Git
configuration, and scratch exceptions are client-specific; neither profile is
a promise that no filesystem object outside the project is ever readable.

- Codex extends `:workspace`, denies `:root`, and allows `:minimal`. The macOS
  Python runtime bundle at `/Library/Frameworks/Python.framework` and Apple's
  `/Library/Developer/CommandLineTools` are readable without write access.
  `/System/Library/Perl` is also read-only so system `shasum` can load its
  interpreter library, core modules, and native modules across OS upgrades.
  This does not grant `/Library/Perl`, user modules, or the rest of
  `/System/Library`. The
  read-only `/usr/local/bin` exception lets the installed Python launcher
  symlink reach its granted framework while preserving PATH. It also makes
  other files in that launcher directory readable; unrelated symlink targets
  and the rest of `/usr/local` remain denied. These runtime exceptions cover
  upgrades within their bundles without granting the rest of `/Library`.
  The owner explicitly approved read-only `~/.gitconfig` access for normal Git
  startup and the exact `~/.gitignore_global` file for existing ignore rules.
  Their contents are readable to contained commands generally, not
  exclusively to Git. Includes and other ignore files in denied locations still require
  separate approval; there is no home-directory grant or global-config/PATH
  rewrite. Native temp allowances remain for development tools.
  Secret patterns remain denied and
  harness/control paths remain read-only. Approvals use `on-request` and the
  human reviewer. Avoid legacy `sandbox_mode` settings: they can replace the
  permission profile. Project configuration takes effect only in a trusted
  project and a newly started session.
- Claude requires its native sandbox to be available before shell execution.
  `blockReadsOutsideWorkingDirectories` restricts Read/Grep/Glob/LSP and also
  restricts shell reads under the documented user-data roots. Shell descendants
  inherit the native boundary. Secret read/write and control-write restrictions
  apply inside the project. Hook failures block, and bypass mode is disabled.
- The small `guard_tool.py` adapter separates native reads from edits. Reading
  instructions and dependency source no longer requests write permission.
  Other tools still pass to the unchanged shared write guard.

Environment/key patterns are deliberately conservative. Native `.env*` denials
also cover example templates on clients where a glob denial wins over an exact
exception. Do not weaken all environment-file denials to edit a template; use
a human-controlled edit outside the restricted session. Files covered by the
secret-path denials remain unavailable. Do not assume approved runtime files
or the explicit Git configuration exception cannot contain sensitive values.

## Explicit exceptions

An agent must explain the exact path, read/write access, and purpose before
requesting additional access. It must not modify its own permissions, add a
working directory, or retry another tool to evade a denial. User approval in
conversation is not itself an OS permission grant.

Prefer an exact read-only file grant, removed after the task:

- **Codex:** the user can add that absolute file as `"read"` in the active
  permission profile through user-controlled configuration, then start a new
  session. Preserve the root denial and all narrower secret/control rules.
  The native suite verifies this permission shape; it does not simulate a user
  clicking an approval prompt in Desktop.
- **Claude:** user-controlled `--settings` or user settings can add the exact
  absolute file to `sandbox.filesystem.allowRead`; read it through sandboxed
  Bash. Native Read still requires a working directory. `/add-dir` alone is
  broader and can enable writes; use it only when the user intends that scope,
  with explicit write denials if the reference directory must stay read-only.

Some normal Docker/build commands need more authority than the shell sandbox
has. Claude allows a retry only through the explicit
`Bash(dangerouslyDisableSandbox:true)` ask rule, including in modes that normally
auto-approve tools. There are no automatic excluded commands or Docker socket
grants. Such approval removes the shell sandbox for that invocation: review the
actual command and its workspace-controlled inputs. Codex likewise retains
human approval for escalation. Never turn a failed probe into an unsandboxed
retry and then count it as a sandbox pass.

Review existing user/managed command approvals too. A previously granted broad
command rule can authorize host execution without a fresh prompt; repository
settings do not revoke those grants. Prefer narrow, temporary approvals.

This slice does not isolate a Docker daemon, development database, MCP server,
desktop automation tool, or credentialed remote connector. Those are separate
authorities. Codex command networking is unchanged; Claude uses its native
network approval behavior. This is not an egress-isolation or privileged-runner
implementation. Arbitrary code can also overwrite ordinary writable source;
preserve existing work and review changes before applying or publishing them.

## Automated configuration and adapter tests

Python 3.11+ is required. No model or database is used:

```sh
make ai-hooks-test
# Or just the project-specific tests:
make agent-containment-test
```

These tests parse both configurations, preserve secret/control restrictions,
exercise both read/write adapters, and reject false native successes. They run
with the existing AI-hook gate in CI and `make quality-full`. They do not prove
the effective settings of a running client.

## Native host conformance

Run from a normal Terminal with the installed clients available:

```sh
make codex-permissions-test
make claude-permissions-test
```

The Codex runner checks `--codex-bin` or `CODEX_BIN`, then `PATH`, then known
macOS Desktop bundle locations. A separate CLI installation or a permanent
`PATH` change is not needed when a supported Desktop bundle is installed.
For a custom installation, select its executable explicitly:

```sh
sh scripts/ai-hooks/verify-codex-permissions.sh --codex-bin /absolute/path/to/codex
```

An invalid explicit selection fails instead of silently choosing a different
client. The selected executable and version are recorded with the results.
The runner also prints and records the probe's Python executable, resolved
target, and runtime root. Native Codex probes execute the resolved interpreter
directly, and each case records that executable. This avoids requiring access
to a launcher symlink outside the granted runtime bundle. A missing or
inaccessible interpreter fails before launch; no alternate interpreter or
unsandboxed retry is used. This stdlib-only probe does not verify every shell
alias, virtual-environment prefix, or `PATH` entry used in normal development.
Commands through an outside launcher symlink can still require a separately
reviewed toolchain allowance. The outside-data symlink probe remains unchanged.

Codex's `:minimal` set may not include a separately installed interpreter.
The actual project profile includes the macOS Python
framework bundle above; it does not pin a Python version. Other installation
locations still require a reviewed runtime grant if the native defaults do
not cover them. A failed executable launch is not a denied-file pass: review
the reported failure and grant only the necessary runtime subtree read access
in the actual profile. The checker never adds such grants itself.

The Codex check invokes the real `codex sandbox` with the candidate profile's
original name and managed constraints. It uses no model. Keeping the original
name avoids inventing a profile that a managed allowlist might reject. Managed
settings can still narrow or replace permissions; the complete native suite
must pass, including the exact read-grant cases, before recording success.
The receipt records observed behavior in this separate CLI sandbox; it does
not attest the full merged policy, Desktop's selected profile, or every hook event.

The Claude check uses two bounded print-mode sessions and their Bash tools;
it needs an existing Claude login and may consume subscription/API usage. It
disables unrelated MCP connections and uses only synthetic fixture content.
The checker accepts tool-result evidence, never the model's narrative verdict.
No command is automatically retried unsandboxed.
Directly invoking `run_claude.py` without `--run` only prepares fixtures and
returns an incomplete result; the explicit Make target supplies `--run`.

Fixtures are created in a new nested Git workspace under
`storage/framework/testing`; all read/write/rename targets are synthetic. The
outside sentinel is a sibling of that workspace, not a real personal file.
The probes check allowed source access, denied secrets/control writes,
outside-file denial through descendants and symlinks, and narrow explicit read
grants without unrelated reads or writes. A denial must be an actual EACCES or
EPERM from the running probe. Missing files, launch failures, unavailable
clients, skipped cases, invalid settings, and timeouts never count as passing.

The current agent's outer sandbox may prevent creating synthetic secret-named
fixtures or launching another OS sandbox. That is an unavailable check, not a
successful native denial. Use normal Terminal, or explicitly approve a separate
owned fixture directory via the runner's `--fixture-parent` option. Never probe
real secrets, remove existing data, or weaken the policy to make a check pass.

Fixtures and JSON receipts are retained for inspection. The scripts never
recursively delete directories. Receipts record policy digests, client version,
case outcomes, and synthetic fixture paths; they exclude private file contents
and raw client transcripts. Keep generated evidence out of commits.

The host runners pin each owned receipt directory before starting a client.
They write private receipts through that directory descriptor and atomic
replacement, rejecting substituted directories, symlinks, and hardlinks. A
failed client verdict does not permit a redirected host write. Host validation
also uses bounded, descriptor-based reads:
canaries are limited to 128 bytes and each control file to 2 MiB. Symlinks at
any path component, hardlinks, and nonregular files are rejected before content
is read; fixture enumeration also rejects directory aliases. Process cleanup
checks the entire POSIX group created by each launcher even after its direct
child exits or closes its output pipes, then kills survivors after a bounded
grace period. A cleanup failure fails the check. Deliberately detached sessions
are outside this process-group cleanup mechanism; native filesystem containment
must still constrain their access.

## Fresh sessions and development commands

The candidate-policy suites above are separate from checking normal startup in
the actual checkout. Run the latter from a normal Terminal:

```sh
make agent-development-test
# Optional, one client at a time:
python3 scripts/agent-containment/run_development.py --run --client codex
python3 scripts/agent-containment/run_development.py --run --client claude
```

This check preserves the clients' normal project settings and selected
permission mode. It never trusts a project automatically or supplies a
replacement filesystem profile. Codex's fresh app-server reports the winning
project configuration origins, trusted hook registrations, loaded instructions,
and new thread's permission profile. Its command check uses the server's startup
policy; it does not prove model-issued tool hooks ran. For metadata alone:

```sh
python3 scripts/agent-containment/codex_session.py
```

To verify Codex hooks execute during a real model-issued tool call, run:

```sh
make codex-model-hooks-test
```

This separate opt-in check submits one model turn through a fresh app-server
using normal authentication, the selected model, and the unchanged project
policy. It asks for one read of a new nonsecret owned fixture. A pass requires
the native command result and matching synchronous start/completion events for
the project `UserPromptSubmit` hook and both `PreToolUse` hooks. Trusted
registration, model prose, or hook completion with a failed status cannot pass.
Pre-tool run IDs must bind to the actual tool call; project controls and fixture
content must remain unchanged. Client approval requests fail the check rather
than being automatically answered. `PermissionRequest` is conditional and is
not exercised by the approved workspace read.

To exercise that conditional hook with a real approval request:

```sh
make codex-permission-hook-test
```

This separate check asks the model to request approval for one exact read of a
newly owned nonsecret fixture outside the project. Before replying, it requires
the native prompt hook, both pre-tool hooks, and the PermissionRequest hook to
complete successfully and bind to the same turn and command. It then sends
`cancel`, the negative response offered by the installed client's ordinary
command-approval flow. It requires native request resolution, a declined-command
event and an interrupted turn, and verifies that the fixtures and project
controls remain unchanged. The declined command must have no process or output.
No host access, session grant, or persistent permission rule is approved. The
check proves the request and cancellation path; it does not test accepting a
host-execution request.

Both fixture directories and a sanitized receipt are retained. This opt-in
check preserves the normal selected model, authentication, project policy,
and human approval setting. Run it from normal Terminal if the app's outer
sandbox prevents starting the client; never relax containment to get a pass.
The receipt distinguishes a received approval request from a successfully sent
cancellation. Protocol failures report fixed check names without retaining commands,
justifications or raw events. An omitted decision list uses the protocol's
default decisions; an explicit list must offer `cancel`. Neither case grants
permission, and failed validation sends no approval response.

The checker retains a sanitized receipt and owned fixture, never raw model or
hook output. Client startup can write its normal logs/cache. An app-launched
nested sandbox failure is unavailable evidence; run from normal Terminal
without relaxing policy. To run this check and the full Claude candidate-policy
suite independently, including when one fails:

```sh
make -k -j1 codex-model-hooks-test claude-permissions-test
```

Claude uses one bounded print-mode model session with Read, Edit, and one Bash
invocation without a sandbox-bypass flag. Exact owned edit and workspace shell
commands are pre-approved; outside Read is not. Native tool results, hook
events, and the observed edit are required.
Claude does not expose a complete live merged-policy attestation in this stream:
the receipt proves observed project hook and tool behavior, not every setting's
source. Unrelated MCP connections and browser integration are disabled for this
check. Neither check attests an already-open Desktop conversation. Claude's
interactive `/status` shows loaded setting sources; `/sandbox` has a Config tab
for resolved filesystem restrictions. Viewing those panels does not require
changing the selected sandbox mode, and configuration visibility is separate
from OS enforcement evidence.

Codex's 19 command checks cover repository and dependency-source reads, owned
file writes and renames, direct/child/grandchild/symlink outside-read denials,
ordinary PATH startup for Git/Make/Python, Git status, a fixed-string search,
and `make publish-guard` plus `make ai-hooks-test`. Two `shasum -a 256` checks
require the exact owned source digest and successful verification of a fixed
owned-source manifest supplied through stdin. Search selects ripgrep if
discoverable, otherwise the standard `/usr/bin/grep`, before running either
check. Both required search receipts name the same selected tool and require
successful version and sentinel output. A launched command's failure never
triggers a retry with another tool. Standalone ripgrep is not a prerequisite
merely because Codex bundles it. These receipts use protocol version 3.
Claude's Bash command
runs the 15 workspace checks under a distinct receipt protocol. It contains no
outside target, and the separate native Read must still deny the outside
sentinel. Recognized outside shell reads can request another native approval
that headless prompting cannot answer; the checker does not disguise or retry
that denied command. This fresh Claude development check therefore does not
prove OS denial through shell descendants or symlinks. The separate 17-case
Claude candidate-policy suite supplies that proof for its tested configuration.

The development check rejects Git's ignore-file access-denial warning even if
the command exits zero, including when it comes from `make publish-guard`.
It preserves the normal Git configuration and excludes; no global-ignore
override or environment rewrite hides a failure. Other zero-exit stderr is
not a general failure criterion. In particular, Git can still report denied
access to protected environment templates under the secret policy above.

Failed command receipts retain numeric return codes/errno, fixed diagnostic
categories, and bounded tool installation paths, never raw stdout/stderr or
environment values. The reported executable is pre-launch PATH discovery
metadata; it does not prove which executable a later PATH fallback selected.
A developer-tool error is not evidence that tools need installing or that a
particular filesystem grant is required.

Required dependencies must already be installed. No package installation,
Docker daemon, database, application
build, or browser test runs here. The supported Node workflow uses the pinned
Compose container; an older host Node cannot establish its usability. Commands
requiring Docker still need explicit host authority.

Fresh owned fixtures are retained under `storage/framework/testing` and, for
outside-read checks, a sibling `<project-name>-containment-checks` directory.
Only newly created nonsecret sentinels are touched. The caller can choose an
approved `--outside-parent` outside both the checkout and native temporary-path
allowances. Receipts contain fixed case labels, sanitized diagnostics and input
digests. Failed or missing evidence makes the command fail. A nested native
sandbox that cannot start is unavailable evidence; use normal Terminal without
relaxing the project policy. Client startup can update its usual authentication,
cache, and log state; the checker does not edit global settings.

## References

- [Codex permission profiles](https://learn.chatgpt.com/docs/permissions)
- [Codex sandboxing](https://learn.chatgpt.com/docs/sandboxing)
- [Claude outside-working-directory reads](https://code.claude.com/docs/en/settings-reference#permissions-blockreadsoutsideworkingdirectories)
- [Claude sandbox scope and exceptions](https://code.claude.com/docs/en/sandboxing)
- [Claude hook failure behavior](https://code.claude.com/docs/en/hooks#block-the-action-when-a-hook-fails)
