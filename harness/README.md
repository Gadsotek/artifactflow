# Cross-Repository AI Harness Contract

`ai-hooks-contract.json` defines the intentionally shared AI safety harness. Repositories using
the contract copy the same contract version and hashes.

- `shared_files` must be byte-identical.
- `normalized_files` may differ only by their declared `project_substitutions`; the test replaces
  those local values with stable placeholders before hashing.
- A deliberate harness change updates every participating repository together, then bumps
  `contract_version` and the affected hashes.

`tests/Feature/Architecture/AiHarnessDriftContractTest.php` enforces the ArtifactFlow side of the
contract without reading a sibling checkout in CI.
