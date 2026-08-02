from __future__ import annotations

import os
from fnmatch import fnmatch

from policy_types import Finding


REPOSITORY_ROOT = os.path.realpath(os.path.join(os.path.dirname(__file__), "..", ".."))

SAFE_TEMPLATE_FILES = {
    ".env.example",
    ".env.production.example",
    ".envrc.example",
    ".npmrc.example",
    "settings.example.json",
}

SECRET_FILE_NAMES = {
    ".env",
    ".npmrc",
    "auth.json",
    "credentials",
    "credentials.json",
    "service-account.json",
}

SECRET_EXTENSIONS = (
    ".pem",
    ".key",
    ".p12",
    ".pfx",
    ".jks",
    ".keystore",
)

GENERATED_PATH_PREFIXES = (
    "node_modules/",
    "vendor/",
    "public/build/",
    "playwright-report/",
    "test-results/",
    "storage/framework/views/",
    "storage/logs/",
    "storage/phpstan/",
)

PROTECTED_CONTROL_PATH_PREFIXES = (
    ".git/",
)

APPROVAL_CONTROL_PATH_PREFIXES = (
    ".claude/",
    ".codex/",
    ".github/workflows/",
    ".semgrep/",
    "AGENTS.md",
    "CLAUDE.md",
    "Makefile",
    "SECURITY.md",
    "THREAT-MODEL.md",
    "harness/",
    "phpstan.neon",
    "phpunit.xml",
    "rector.php",
    "scripts/ai-hooks/",
    "tests/Feature/Architecture/AiHarnessDriftContractTest.php",
    "tests/Feature/Infrastructure/HarnessPolicyConfigurationTest.php",
)

def normalize_path_token(token: str) -> str:
    stripped = token.strip().strip("'\"")
    while stripped.endswith((",", ":", ")")):
        stripped = stripped[:-1]
    while stripped.startswith("@") and len(stripped) > 1:
        stripped = stripped[1:]

    return stripped


def normalize_relative_path_token(token: str) -> str:
    normalized = normalize_path_token(token)

    while normalized.startswith("./"):
        normalized = normalized[2:]

    candidate = normalized if os.path.isabs(normalized) else os.path.join(REPOSITORY_ROOT, normalized)
    real_path = os.path.realpath(candidate)
    try:
        relative = os.path.relpath(real_path, REPOSITORY_ROOT)
    except ValueError:
        relative = normalized

    if (
        relative != "."
        and relative != ".."
        and not relative.startswith(f"..{os.sep}")
        and not os.path.isabs(relative)
    ):
        normalized = relative
    elif os.path.isabs(normalized):
        normalized = real_path
    else:
        normalized = os.path.normpath(normalized)

    while normalized.startswith("./"):
        normalized = normalized[2:]

    return normalized.replace(os.sep, "/")

def is_secret_path(path: str) -> bool:
    normalized = normalize_path_token(path)
    if normalized == "":
        return False

    if secret_glob_matches(normalized):
        return True

    if ":" in normalized:
        possible_path = normalized.rsplit(":", 1)[1]
        if possible_path != normalized and is_secret_path(possible_path):
            return True

    if "=" in normalized:
        key, possible_path = normalized.split("=", 1)
        if key != "" and is_secret_path(possible_path):
            return is_secret_path(possible_path)

    normalized = normalize_relative_path_token(normalized)

    base = os.path.basename(normalized)
    if base in SAFE_TEMPLATE_FILES:
        return False

    lower = base.lower()
    if lower in SECRET_FILE_NAMES:
        return True

    if lower.startswith(".env."):
        return True

    return lower.endswith(SECRET_EXTENSIONS)


def secret_glob_matches(path: str) -> bool:
    if not any(marker in path for marker in "*?["):
        return False

    base = os.path.basename(path)
    if base in SAFE_TEMPLATE_FILES:
        return False

    secret_names = [*SECRET_FILE_NAMES, *[f"secret{extension}" for extension in SECRET_EXTENSIONS]]

    return any(fnmatch(name, base) for name in secret_names) \
        or any(fnmatch(f"config/prod{extension}", path) for extension in SECRET_EXTENSIONS)


def is_generated_path(path: str) -> bool:
    normalized = normalize_relative_path_token(path)
    if normalized.endswith("/.gitignore"):
        return False

    return any(normalized.startswith(prefix) for prefix in GENERATED_PATH_PREFIXES)


def is_protected_control_path(path: str) -> bool:
    normalized = normalize_relative_path_token(path)

    return any(normalized == prefix.rstrip("/") or normalized.startswith(prefix) for prefix in PROTECTED_CONTROL_PATH_PREFIXES)


def is_approval_control_path(path: str) -> bool:
    normalized = normalize_relative_path_token(path)

    return any(
        normalized == prefix.rstrip("/") or normalized.startswith(prefix)
        for prefix in APPROVAL_CONTROL_PATH_PREFIXES
    )

def file_write_findings(path: str) -> list[Finding]:
    if path == "":
        return []

    if is_protected_control_path(path):
        return [
            Finding(
                code="protected_control_write",
                action="deny",
                reason="Refusing to modify Git internal files through an AI hook.",
            )
        ]

    if is_secret_path(path):
        return [
            Finding(
                code="secret_file_write",
                action="deny",
                reason="Refusing to create or modify secret-bearing local files from an AI hook.",
            )
        ]

    if is_generated_path(path):
        return [
            Finding(
                code="generated_file_write",
                action="deny",
                reason="Refusing to edit generated/runtime output directly. Change source files instead.",
            )
        ]

    if is_approval_control_path(path):
        return [
            Finding(
                code="control_plane_write",
                action="ask",
                reason="Modifying AI instructions, security contracts, hooks, CI, or repository enforcement requires explicit user approval.",
            )
        ]

    return []
