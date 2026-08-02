from __future__ import annotations

import re

from policy_types import Finding


def scan_prompt(text: str) -> list[Finding]:
    if text == "":
        return []

    patterns = {
        "private_key": r"-----BEGIN [A-Z ]*PRIVATE KEY-----",
        "openai_api_key": r"\bsk-[A-Za-z0-9_-]{20,}\b",
        "github_token": r"\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})\b",
        "aws_access_key": r"\bAKIA[0-9A-Z]{16}\b",
        "secret_assignment": r"(?mi)^[A-Z0-9_]*(?:KEY|SECRET|TOKEN|PASSWORD|PASS|CREDENTIAL)[A-Z0-9_]*\s*=\s*\S+",
    }

    findings: list[Finding] = []
    for code, pattern in patterns.items():
        if re.search(pattern, text):
            findings.append(
                Finding(
                    code=code,
                    action="deny",
                    reason="The prompt appears to contain secrets or credentials. Redact them before continuing.",
                )
            )

    return findings
