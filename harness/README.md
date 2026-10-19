# Cross-Repository AI Harness Contract

ArtifactFlow is the security-sensitive, versioned artifact vault used as the reference workload for an evolving AI engineering harness
used with Codex and Claude Code. The workflow combines durable repository instructions, tests before
production behavior, explicit agent handoffs, conservative command and file guards, isolated PHP and
browser test databases, adversarial review, human approval boundaries, and a comprehensive release
gate. The reusable concepts are documented as a case study at
[artifactflow.app/engineering-harness](https://artifactflow.app/engineering-harness/).

This directory is deliberately smaller than a universal framework. It versions the portion that is
currently shared across participating repositories: the tested agent-hook policy and its declared
project-specific substitutions. ArtifactFlow-specific application architecture, threat tests, and
Laravel/Playwright wrappers remain in their normal repository locations.

`ai-hooks-contract.json` defines the intentionally shared AI safety harness. Repositories using
the contract copy the same contract version and hashes. Version 8 narrows
literal-pipe masking to reviewed static search commands. Executable wrapper
payloads and compound commands retain interpreter-pipeline denials; ordinary
quoted search patterns remain usable. ArtifactFlow and HNS share this contract.

- `shared_files` must be byte-identical.
- `normalized_files` may differ only by their declared `project_substitutions`; the test replaces
  those local values with stable placeholders before hashing.
- A deliberate harness change updates every participating repository together, then bumps
  `contract_version` and the affected hashes.

`tests/Feature/Architecture/AiHarnessDriftContractTest.php` enforces the ArtifactFlow side of the
contract without reading a sibling checkout in CI.

## Project-native containment

The [native containment layer](../scripts/agent-containment/README.md) configures
Codex and Claude filesystem boundaries and validates them with synthetic
conformance probes. It is project-specific and outside the shared hook byte
contract. Hooks remain defense in depth; subprocess containment comes from the
native sandbox.
