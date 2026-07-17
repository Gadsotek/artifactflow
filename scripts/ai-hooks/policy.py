from __future__ import annotations

import json
import os
import re
import shlex
import sys
from fnmatch import fnmatch
from dataclasses import dataclass
from typing import Any


REPOSITORY_ROOT = os.path.realpath(os.path.join(os.path.dirname(__file__), "..", ".."))
SEPARATORS = {"&&", "||", ";", "|"}

SHELL_WRAPPERS = {
    "bash",
    "command",
    "docker",
    "env",
    "eval",
    "make",
    "nice",
    "nohup",
    "sh",
    "timeout",
    "xargs",
    "zsh",
}

SECRET_REDIRECTION_OPERATORS = {"<", "<<", "<<<", "0<"}
WRITE_REDIRECTION_OPERATORS = {">", ">>", "1>", "1>>", "2>", "2>>", "&>", "&>>"}

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

READ_COMMANDS = {
    "awk",
    "base64",
    "busybox",
    "cat",
    "column",
    "compgen",
    "cut",
    "declare",
    "ex",
    "expand",
    "fold",
    "grep",
    "head",
    "hexdump",
    "jq",
    "less",
    "more",
    "nl",
    "od",
    "paste",
    "pr",
    "rev",
    "rg",
    "sed",
    "set",
    "shuf",
    "sort",
    "strings",
    "tac",
    "tail",
    "wc",
    "xxd",
}

SECRET_FILE_ACCESS_COMMANDS = READ_COMMANDS | {
    "cp",
    "dd",
    "install",
    "rsync",
    "tar",
    "unzip",
    "zip",
}

SECRET_AWARE_GIT_SUBCOMMANDS = {
    "cat-file",
    "diff",
    "grep",
    "log",
    "show",
}

SECRET_AWARE_INTERPRETERS = {
    "node",
    "perl",
    "php",
    "python",
    "python3",
    "ruby",
}

SECRET_AWARE_EDITORS = {
    "ed",
    "emacs",
    "nano",
    "pico",
    "vi",
    "view",
    "vim",
}

SHELL_EXECUTORS = {
    "bash",
    "dash",
    "fish",
    "ksh",
    "sh",
    "zsh",
}

INLINE_INTERPRETER_OPTIONS = {
    "node": {"-e", "--eval"},
    "perl": {"-e", "-E", "-ne", "-nE", "-pe", "-pE"},
    "php": {"-r"},
    "python": {"-c"},
    "python3": {"-c"},
    "ruby": {"-e"},
}

DYNAMIC_PATH_COMMANDS = SECRET_FILE_ACCESS_COMMANDS | SECRET_AWARE_EDITORS | {
    ".",
    "curl",
    "ln",
    "mapfile",
    "mv",
    "openssl",
    "read",
    "sed",
    "source",
    "tee",
}

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
    ".claude/settings.json",
    ".codex/",
    ".git/",
    "Makefile",
    "scripts/ai-hooks/",
)


@dataclass(frozen=True)
class Finding:
    code: str
    action: str
    reason: str


def load_event() -> dict[str, Any]:
    raw = sys.stdin.read()
    if not raw.strip():
        return {}

    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return {"raw": raw}

    if isinstance(data, dict):
        return data

    return {"value": data}


def get_nested(data: dict[str, Any], path: tuple[str, ...]) -> Any:
    current: Any = data
    for key in path:
        if not isinstance(current, dict) or key not in current:
            return None
        current = current[key]

    return current


def extract_command(event: dict[str, Any]) -> str:
    candidates = (
        ("tool_input", "command"),
        ("tool_input", "cmd"),
        ("input", "command"),
        ("input", "cmd"),
        ("parameters", "command"),
        ("parameters", "cmd"),
        ("command",),
        ("cmd",),
    )

    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str):
            return value
        if isinstance(value, list):
            return " ".join(shlex.quote(str(part)) for part in value)

    return ""


def extract_prompt(event: dict[str, Any]) -> str:
    candidates = (
        ("prompt",),
        ("user_prompt",),
        ("message",),
        ("input", "prompt"),
        ("input", "message"),
        ("tool_input", "prompt"),
    )

    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str):
            return value

    raw = event.get("raw")
    return raw if isinstance(raw, str) else ""


def extract_file_paths(event: dict[str, Any]) -> list[str]:
    candidates = (
        ("tool_input", "file_path"),
        ("tool_input", "path"),
        ("tool_input", "filename"),
        ("input", "file_path"),
        ("input", "path"),
        ("parameters", "file_path"),
        ("parameters", "path"),
        ("file_path",),
        ("path",),
    )

    paths: list[str] = []
    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str) and value not in paths:
            paths.append(value)

    tool_name = event.get("tool_name")
    if isinstance(tool_name, str) and normalize_tool_name(tool_name) == "apply_patch":
        for path in extract_apply_patch_paths(extract_patch_payload(event)):
            if path not in paths:
                paths.append(path)

    return paths


def normalize_tool_name(tool_name: str) -> str:
    return re.sub(r"[./:_]+", "_", tool_name.strip().lower())


def extract_patch_payload(event: dict[str, Any]) -> str:
    candidates = (
        ("tool_input", "patch"),
        ("tool_input", "command"),
        ("tool_input", "input"),
        ("input", "patch"),
        ("input", "command"),
        ("parameters", "patch"),
        ("parameters", "command"),
        ("patch",),
        ("command",),
    )

    for path in candidates:
        value = get_nested(event, path)
        if isinstance(value, str):
            return value

    return ""


def extract_apply_patch_paths(patch: str) -> list[str]:
    target_prefixes = (
        "*** Add File: ",
        "*** Update File: ",
        "*** Delete File: ",
        "*** Move to: ",
    )
    paths: list[str] = []

    for line in patch.splitlines():
        for prefix in target_prefixes:
            if not line.startswith(prefix):
                continue

            path = line[len(prefix):].strip()
            if path and path not in paths:
                paths.append(path)
            break

    return paths


def tokenize(command: str) -> list[str]:
    if command == "":
        return []

    try:
        lexer = shlex.shlex(command, posix=True, punctuation_chars=True)
        lexer.whitespace_split = True
        return list(lexer)
    except ValueError:
        return re.findall(r"[^\s]+", command)


def command_segments(tokens: list[str]) -> list[list[str]]:
    segments: list[list[str]] = []
    current: list[str] = []

    for token in tokens:
        if token in SEPARATORS:
            if current:
                segments.append(current)
                current = []
            continue
        current.append(token)

    if current:
        segments.append(current)

    return segments


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


def is_shell_assignment(token: str) -> bool:
    return re.match(r"^[A-Za-z_][A-Za-z0-9_]*=", token) is not None


def segment_command_position(segment: list[str]) -> int | None:
    for index, token in enumerate(segment):
        if not is_shell_assignment(token):
            return index

    return None


def segment_command(segment: list[str]) -> tuple[str, list[str]] | None:
    position = segment_command_position(segment)
    if position is None:
        return None

    command = os.path.basename(normalize_path_token(segment[position]))
    return command, segment[position + 1:]


def has_shell_expansion(token: str) -> bool:
    normalized = normalize_path_token(token)
    return "$" in normalized or "`" in normalized or "<(" in normalized or ">(" in normalized


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


def secret_file_read_finding() -> Finding:
    return Finding(
        code="secret_file_read",
        action="deny",
        reason="Refusing to read, print, copy, archive, or write secret-bearing files. Use example files or redacted output instead.",
    )


def command_contains_secret_substitution(command: str) -> bool:
    patterns = (
        r"\$\([^)]*(?:\.env|\.npmrc|auth\.json|credentials(?:\.json)?|service-account\.json|[^\s)]*\.(?:pem|key|p12|pfx|jks|keystore))[^)]*\)",
        r"\$<\s*(?:\.env|\.npmrc|auth\.json|credentials(?:\.json)?|service-account\.json|[^\s)]*\.(?:pem|key|p12|pfx|jks|keystore))",
        r"`[^`]*(?:\.env|\.npmrc|auth\.json|credentials(?:\.json)?|service-account\.json|[^\s`]*\.(?:pem|key|p12|pfx|jks|keystore))[^`]*`",
        r"chr\s*\(\s*46\s*\)\s*\+\s*['\"]env['\"]",
    )

    return any(re.search(pattern, command, re.IGNORECASE) for pattern in patterns)


def redirection_target(segment: list[str], index: int) -> str | None:
    token = normalize_path_token(segment[index])
    if token in SECRET_REDIRECTION_OPERATORS:
        if index + 1 >= len(segment):
            return None

        return segment[index + 1]

    attached = re.match(r"^(?:\d*)(?:<<<|<<|<)(.+)$", token)
    if attached is not None:
        return attached.group(1)

    return None


def write_redirection_target(segment: list[str], index: int) -> str | None:
    token = normalize_path_token(segment[index])
    if token in WRITE_REDIRECTION_OPERATORS:
        if index + 1 >= len(segment):
            return None

        return segment[index + 1]

    attached = re.match(r"^(?:\d*|&)(?:>>|>)(.+)$", token)
    if attached is not None:
        return attached.group(1)

    return None


def segment_has_secret_redirection(segment: list[str]) -> bool:
    for index, _token in enumerate(segment):
        target = redirection_target(segment, index)
        if target is not None and is_secret_path(target):
            return True

    return False


def wrapper_argument_has_secret_read(token: str) -> bool:
    if is_secret_path(token):
        return True

    inner_tokens = tokenize(token)
    if len(inner_tokens) <= 1:
        return False

    return any(find_secret_read(inner_segment) is not None for inner_segment in command_segments(inner_tokens))


def openssl_argument_is_secret(token: str) -> bool:
    if is_secret_path(token):
        return True

    normalized = normalize_path_token(token)
    if "=" not in normalized:
        return False

    option, possible_path = normalized.split("=", 1)
    if option.lstrip("-").lower() in {"cafile", "cert", "in", "inkey", "key"}:
        return is_secret_path(possible_path)

    return False


def option_has_recursive_delete(option: str) -> bool:
    if option == "--recursive":
        return True
    if not option.startswith("-") or option.startswith("--"):
        return False

    return "r" in option[1:] or "R" in option[1:]


def rm_targets(segment: list[str]) -> list[str]:
    targets: list[str] = []
    parsing_options = True

    for token in segment[1:]:
        if token == "--":
            parsing_options = False
            continue
        if parsing_options and token.startswith("-"):
            continue
        targets.append(token)

    return targets


def has_dangerous_root_target(targets: list[str]) -> bool:
    dangerous_targets = {"/", "/*", ".", "./", "..", "../", "~", "~/", "$HOME", "${HOME}"}
    return any(normalize_path_token(target) in dangerous_targets for target in targets)


def find_recursive_rm(segment: list[str]) -> Finding | None:
    if not segment or segment[0] != "rm":
        return None

    recursive = any(option_has_recursive_delete(token) for token in segment[1:])
    if not recursive:
        return None

    targets = rm_targets(segment)
    if has_dangerous_root_target(targets):
        return Finding(
            code="recursive_rm_root",
            action="deny",
            reason="Refusing recursive deletion of a root, home, current, or parent directory target.",
        )

    return Finding(
        code="recursive_rm",
        action="ask",
        reason="Recursive deletion commands such as rm -rf require explicit user approval.",
    )


def find_file_deletion_or_dispatch(segment: list[str]) -> Finding | None:
    parsed = segment_command(segment)
    if parsed is None:
        return None

    command, arguments = parsed
    if command in {"rm", "shred", "unlink"}:
        return Finding(
            code="file_deletion",
            action="deny",
            reason="Refusing file deletion commands from an AI hook.",
        )

    if command == "find" and any(
        argument in {"-delete", "-exec", "-execdir", "-ok", "-okdir"}
        for argument in arguments
    ):
        return Finding(
            code="find_execution",
            action="deny",
            reason="Refusing find deletion or command-execution actions from an AI hook.",
        )

    if command == "xargs":
        return Finding(
            code="xargs_execution",
            action="deny",
            reason="Refusing xargs command dispatch because the executed command is data-dependent.",
        )

    return None


def find_git_risk(segment: list[str]) -> Finding | None:
    if len(segment) < 2 or segment[0] != "git":
        return None

    subcommand = segment[1]
    if subcommand == "push":
        return Finding(
            code="git_push",
            action="ask",
            reason="git push requires explicit user approval for this exact push.",
        )

    if subcommand == "reset" and "--hard" in segment[2:]:
        return Finding(
            code="git_reset_hard",
            action="ask",
            reason="git reset --hard can discard local work and requires explicit approval.",
        )

    if subcommand == "clean":
        return Finding(
            code="git_clean",
            action="ask",
            reason="git clean deletes untracked files and requires explicit approval.",
        )

    if subcommand == "checkout" and "--" in segment[2:]:
        return Finding(
            code="git_checkout_discard",
            action="ask",
            reason="git checkout -- can discard file changes and requires explicit approval.",
        )

    if subcommand == "restore":
        return Finding(
            code="git_restore",
            action="ask",
            reason="git restore can discard local changes and requires explicit approval.",
        )

    if subcommand == "branch" and any(token in {"-d", "-D", "--delete"} for token in segment[2:]):
        return Finding(
            code="git_branch_delete",
            action="ask",
            reason="Deleting git branches requires explicit approval.",
        )

    if subcommand == "tag" and any(token in {"-d", "--delete"} for token in segment[2:]):
        return Finding(
            code="git_tag_delete",
            action="ask",
            reason="Deleting git tags requires explicit approval.",
        )

    if subcommand in {"rebase", "filter-branch", "filter-repo"}:
        return Finding(
            code=f"git_{subcommand}",
            action="ask",
            reason=f"git {subcommand} rewrites history or working state and requires explicit approval.",
        )

    return None


def find_secret_read(segment: list[str]) -> Finding | None:
    if not segment:
        return None

    command = os.path.basename(segment[0])
    git_secret_check = command == "git" and len(segment) >= 2 and segment[1] in SECRET_AWARE_GIT_SUBCOMMANDS
    interpreter_secret_check = command in SECRET_AWARE_INTERPRETERS and any(
        secret_name in " ".join(segment[1:])
        for secret_name in SECRET_FILE_NAMES
    )

    if command in {"printenv", "set", "compgen"} or (command == "env" and len(segment) == 1) \
            or (command == "declare" and "-p" in segment[1:]):
        return Finding(
            code="environment_dump",
            action="deny",
            reason="Refusing to dump the process environment because it may contain credentials.",
        )

    if any(is_secret_path(token) for token in segment):
        return secret_file_read_finding()

    if segment_has_secret_redirection(segment):
        return secret_file_read_finding()

    if command in {"source", "."} and any(is_secret_path(token) for token in segment[1:]):
        return secret_file_read_finding()

    if command in SHELL_WRAPPERS and any(wrapper_argument_has_secret_read(token) for token in segment[1:]):
        return secret_file_read_finding()

    if command in SECRET_AWARE_EDITORS and any(is_secret_path(token) for token in segment[1:]):
        return secret_file_read_finding()

    if command == "openssl" and any(openssl_argument_is_secret(token) for token in segment[1:]):
        return secret_file_read_finding()

    if command not in SECRET_FILE_ACCESS_COMMANDS and not git_secret_check and not interpreter_secret_check:
        return None

    for token in segment[1:]:
        if is_secret_path(token):
            return secret_file_read_finding()

    if interpreter_secret_check:
        return secret_file_read_finding()

    return None


def non_option_operands(tokens: list[str]) -> list[str]:
    operands: list[str] = []
    parsing_options = True

    for token in tokens:
        if token == "--":
            parsing_options = False
            continue
        if parsing_options and token.startswith("-"):
            continue

        operands.append(token)

    return operands


def copy_like_targets(segment: list[str]) -> list[str]:
    target_directory: str | None = None
    operands: list[str] = []
    parsing_options = True
    index = 1

    while index < len(segment):
        token = segment[index]

        if token == "--":
            parsing_options = False
            index += 1
            continue

        if parsing_options:
            if token in {"-t", "--target-directory"} and index + 1 < len(segment):
                target_directory = segment[index + 1]
                index += 2
                continue

            if token.startswith("--target-directory="):
                target_directory = token.split("=", 1)[1]
                index += 1
                continue

            if token.startswith("-"):
                index += 1
                continue

        operands.append(token)
        index += 1

    if target_directory is not None:
        return [target_directory]

    if len(operands) >= 2:
        return [operands[-1]]

    return []


def sed_in_place_targets(segment: list[str]) -> list[str]:
    has_in_place_flag = any(
        token == "-i"
        or token.startswith("-i")
        or token == "--in-place"
        or token.startswith("--in-place=")
        for token in segment[1:]
    )
    if not has_in_place_flag:
        return []

    operands = non_option_operands(segment[1:])
    if len(operands) < 2:
        return []

    return operands[1:]


def file_write_findings(path: str) -> list[Finding]:
    if path == "":
        return []

    if is_protected_control_path(path):
        return [
            Finding(
                code="protected_control_write",
                action="deny",
                reason="Refusing to modify AI hook, policy, or repository control files through an AI hook.",
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

    return []


def command_write_targets(segment: list[str]) -> list[str]:
    position = segment_command_position(segment)
    if position is None:
        return []

    command_segment = segment[position:]
    command = os.path.basename(command_segment[0])
    targets: list[str] = []

    for index, _token in enumerate(segment):
        target = write_redirection_target(segment, index)
        if target is not None:
            targets.append(target)

    if command in {"cp", "install", "mv"}:
        targets.extend(copy_like_targets(command_segment))

    if command == "rm":
        targets.extend(rm_targets(command_segment))

    if command == "sed":
        targets.extend(sed_in_place_targets(command_segment))

    if command == "tee":
        targets.extend(non_option_operands(command_segment[1:]))

    return targets


def find_file_write_risk(segment: list[str]) -> Finding | None:
    for target in command_write_targets(segment):
        findings = file_write_findings(target)
        if findings:
            return findings[0]

    return None


def find_dynamic_command_execution(segment: list[str]) -> Finding | None:
    parsed = segment_command(segment)
    if parsed is None:
        return None

    command, arguments = parsed
    if has_shell_expansion(command):
        return Finding(
            code="dynamic_command_execution",
            action="deny",
            reason="Refusing a dynamically constructed command name because static hook policy cannot verify it.",
        )

    if command in {"command", "env", "exec", "nice", "nohup", "timeout", "xargs"} \
            and any(has_shell_expansion(argument) for argument in arguments):
        return Finding(
            code="dynamic_command_execution",
            action="deny",
            reason="Refusing a wrapper that dynamically constructs the command it executes.",
        )

    return None


def inline_option_matches(argument: str, option: str) -> bool:
    if argument == option:
        return True

    if option.startswith("--"):
        return argument.startswith(f"{option}=")

    return argument.startswith(option) and len(argument) > len(option)


def find_inline_interpreter_execution(segment: list[str]) -> Finding | None:
    parsed = segment_command(segment)
    if parsed is None:
        return None

    command, arguments = parsed
    if command in SHELL_EXECUTORS and any(
        argument == "-c"
        or (argument.startswith("-") and not argument.startswith("--") and "c" in argument[1:])
        for argument in arguments
    ):
        return Finding(
            code="inline_interpreter_execution",
            action="deny",
            reason="Refusing inline shell code because its runtime behavior cannot be verified statically.",
        )

    options = INLINE_INTERPRETER_OPTIONS.get(command, set())
    if any(inline_option_matches(argument, option) for argument in arguments for option in options):
        return Finding(
            code="inline_interpreter_execution",
            action="deny",
            reason="Refusing inline interpreter code because it can construct hidden paths or commands at runtime.",
        )

    return None


def find_link_creation(segment: list[str]) -> Finding | None:
    parsed = segment_command(segment)
    if parsed is None or parsed[0] != "ln":
        return None

    return Finding(
        code="link_creation",
        action="deny",
        reason="Refusing symbolic or hard-link creation because aliases can bypass path-based safety checks.",
    )


def find_dynamic_sensitive_path(segment: list[str]) -> Finding | None:
    for target in command_write_targets(segment):
        if has_shell_expansion(target):
            return Finding(
                code="dynamic_write_target",
                action="deny",
                reason="Refusing a dynamically constructed write target because the hook cannot verify its final path.",
            )

    parsed = segment_command(segment)
    if parsed is None:
        return None

    command, arguments = parsed
    if command in DYNAMIC_PATH_COMMANDS and any(has_shell_expansion(argument) for argument in arguments):
        return Finding(
            code="dynamic_sensitive_path",
            action="deny",
            reason="Refusing a dynamically constructed path for a command that can read, copy, link, or expose files.",
        )

    return None


def find_encoded_execution(command: str) -> Finding | None:
    executor_names = "|".join(sorted(SHELL_EXECUTORS | SECRET_AWARE_INTERPRETERS))
    pipes_to_executor = re.search(
        rf"\|\s*(?:(?:command|env|exec|nice|nohup)\s+)*(?:{executor_names})\b",
        command,
        re.IGNORECASE,
    ) is not None
    if not pipes_to_executor:
        return None

    uses_decoder = re.search(
        r"\b(?:base32|base64)\b[^|;&]*(?:-d\b|--decode\b)"
        r"|\bxxd\b[^|;&]*-r\b"
        r"|\bopenssl\b[^|;&]*(?:-d\b|--decode\b)"
        r"|\buudecode\b",
        command,
        re.IGNORECASE,
    ) is not None
    uses_escaped_printf = re.search(
        r"\bprintf\b[^|;&]*(?:\\x[0-9a-f]{2}|\\[0-7]{3})",
        command,
        re.IGNORECASE,
    ) is not None
    if not uses_decoder and not uses_escaped_printf:
        return None

    return Finding(
        code="encoded_execution",
        action="deny",
        reason="Refusing decoded or escaped data piped into a shell or interpreter.",
    )


def find_piped_interpreter_execution(command: str) -> Finding | None:
    executor_names = "|".join(sorted(SHELL_EXECUTORS | SECRET_AWARE_INTERPRETERS))
    if re.search(
        rf"\|\s*(?:(?:command|env|exec|nice|nohup)\s+)*(?:{executor_names})\b",
        command,
        re.IGNORECASE,
    ) is None:
        return None

    return Finding(
        code="piped_interpreter_execution",
        action="deny",
        reason="Refusing data piped into a shell or interpreter because its runtime behavior is opaque to hooks.",
    )


def script_execution_targets(segment: list[str]) -> list[str]:
    parsed = segment_command(segment)
    if parsed is None:
        return []

    command, arguments = parsed
    if command in {".", "source"}:
        return arguments[:1]

    if command in SHELL_EXECUTORS | SECRET_AWARE_INTERPRETERS:
        parsing_options = True
        for argument in arguments:
            if parsing_options and argument == "--":
                parsing_options = False
                continue
            if parsing_options and argument.startswith("-"):
                continue

            return [argument]

        return []

    position = segment_command_position(segment)
    if position is None:
        return []

    command_token = normalize_path_token(segment[position])
    if "/" in command_token:
        return [command_token]

    return []


def find_write_then_execute(command: str) -> Finding | None:
    written_paths: set[str] = set()

    for segment in command_segments(tokenize(command)):
        for target in command_write_targets(segment):
            if not has_shell_expansion(target):
                written_paths.add(normalize_relative_path_token(target))

        execution_paths = {
            normalize_relative_path_token(target)
            for target in script_execution_targets(segment)
            if not has_shell_expansion(target)
        }
        if written_paths.intersection(execution_paths):
            return Finding(
                code="write_then_execute",
                action="deny",
                reason="Refusing to execute a file written earlier in the same command.",
            )

    return None


def find_docker_risk(segment: list[str]) -> Finding | None:
    if len(segment) < 2 or segment[0] != "docker":
        return None

    if len(segment) >= 4 and segment[1] == "compose" and segment[2] == "down":
        if any(token in {"-v", "--volumes"} for token in segment[3:]):
            return Finding(
                code="docker_compose_down_volumes",
                action="ask",
                reason="Removing Docker Compose volumes can delete local databases and requires approval.",
            )

    if len(segment) >= 3 and segment[1] == "volume" and segment[2] in {"rm", "prune"}:
        return Finding(
            code="docker_volume_delete",
            action="ask",
            reason="Deleting Docker volumes requires explicit approval.",
        )

    if len(segment) >= 3 and segment[1] in {"system", "builder"} and segment[2] == "prune":
        return Finding(
            code="docker_prune",
            action="ask",
            reason="Docker prune commands delete local cache/build state and require approval.",
        )

    return None


def find_make_risk(segment: list[str]) -> Finding | None:
    if len(segment) < 2 or segment[0] != "make":
        return None

    if segment[1] in {"down-reset", "test-db-reset"}:
        return Finding(
            code="make_reset",
            action="ask",
            reason=f"make {segment[1]} deletes local data and requires explicit approval.",
        )

    return None


def contains_token_sequence(segment: list[str], sequence: tuple[str, ...]) -> bool:
    sequence_length = len(sequence)
    if sequence_length == 0 or len(segment) < sequence_length:
        return False

    lowered = [token.lower() for token in segment]
    for start in range(0, len(lowered) - sequence_length + 1):
        if tuple(lowered[start:start + sequence_length]) == sequence:
            return True

    return False


def token_contains_direct_test_runner(token: str) -> bool:
    lowered = token.lower()

    return bool(re.search(r"\bphp\s+artisan\s+test\b", lowered)) \
        or bool(re.search(r"\bartisan\s+test\b", lowered)) \
        or bool(re.search(r"(^|/)(pest|phpunit)(\s|$)", lowered)) \
        or bool(re.search(r"vendor/bin/(pest|phpunit)(\s|$)", lowered))


def find_direct_local_test_runner(segment: list[str]) -> Finding | None:
    if not segment:
        return None

    command = os.path.basename(segment[0])
    if command in READ_COMMANDS:
        return None

    if command == "make" and len(segment) >= 2 and segment[1] in {
        "coverage",
        "quality",
        "quality-full",
        "test",
        "type-coverage",
    }:
        return None

    if contains_token_sequence(segment, ("php", "artisan", "test")) \
        or contains_token_sequence(segment, ("artisan", "test")):
        return Finding(
            code="direct_local_test_runner",
            action="deny",
            reason="Refusing direct Laravel test runner commands. Use `make test` or `make test TEST_FILTER=...` so tests run against an isolated test database, never the local app database.",
        )

    if command in SHELL_WRAPPERS:
        for token in segment[1:]:
            if token_contains_direct_test_runner(token):
                return Finding(
                    code="direct_local_test_runner",
                    action="deny",
                    reason="Refusing direct Laravel/Pest/PHPUnit test runner commands. Use `make test` or `make test TEST_FILTER=...` so tests run against an isolated test database, never the local app database.",
                )

    for token in segment:
        executable = os.path.basename(normalize_path_token(token)).lower()
        if executable in {"pest", "phpunit"}:
            return Finding(
                code="direct_local_test_runner",
                action="deny",
                reason="Refusing direct Pest/PHPUnit commands. Use `make test` or `make test TEST_FILTER=...` so tests run against an isolated test database, never the local app database.",
            )

    return None


def find_privilege_or_install_risk(command: str, segment: list[str]) -> Finding | None:
    if segment and segment[0] == "sudo":
        return Finding(
            code="sudo",
            action="ask",
            reason="sudo commands require explicit approval.",
        )

    if len(segment) >= 3 and segment[0] == "chmod" and "777" in segment[1:]:
        return Finding(
            code="chmod_777",
            action="ask",
            reason="chmod 777 weakens filesystem permissions and requires explicit approval.",
        )

    if len(segment) >= 3 and segment[0] == "chown" and "-R" in segment[1:]:
        return Finding(
            code="chown_recursive",
            action="ask",
            reason="Recursive ownership changes require explicit approval.",
        )

    if re.search(r"\b(curl|wget)\b.+\|\s*(sudo\s+)?(sh|bash|zsh|python|python3|php)\b", command, re.IGNORECASE):
        return Finding(
            code="curl_pipe_shell",
            action="ask",
            reason="Piping network content into an interpreter requires explicit approval.",
        )

    return None


def find_database_risk(command: str, segment: list[str]) -> Finding | None:
    if segment and os.path.basename(segment[0]) == "dropdb":
        return Finding(
            code="dropdb",
            action="ask",
            reason="Dropping a database requires explicit approval.",
        )

    if re.search(r"\b(drop\s+database|truncate\s+table)\b", command, re.IGNORECASE):
        return Finding(
            code="sql_destructive",
            action="ask",
            reason="Destructive SQL requires explicit approval.",
        )

    return None


def find_cloud_risk(segment: list[str]) -> Finding | None:
    if len(segment) < 2 or segment[0] not in {"aws", "az", "gcloud", "kubectl", "terraform"}:
        return None

    destructive_words = {"delete", "destroy", "remove"}
    if segment[0] == "terraform" and segment[1] in {"apply", "destroy"}:
        return Finding(
            code="terraform_change",
            action="ask",
            reason=f"terraform {segment[1]} changes infrastructure and requires explicit approval.",
        )

    if any(token in destructive_words for token in segment[1:]):
        return Finding(
            code="cloud_destructive",
            action="ask",
            reason="Cloud or Kubernetes destructive operations require explicit approval.",
        )

    return None


def scan_command(command: str) -> list[Finding]:
    findings: list[Finding] = []
    statements = command.splitlines() or [command]

    if "\n" in command:
        statements = [re.sub(r"\\?\r?\n", " ", command), *statements]

    for statement in statements:
        if command_contains_secret_substitution(statement):
            findings.append(secret_file_read_finding())

        for statement_checker in (
            find_encoded_execution,
            find_piped_interpreter_execution,
            find_write_then_execute,
        ):
            statement_finding = statement_checker(statement)
            if statement_finding is not None:
                findings.append(statement_finding)

        tokens = tokenize(statement)

        for segment in command_segments(tokens):
            for checker in (
                find_dynamic_command_execution,
                find_recursive_rm,
                find_git_risk,
                find_secret_read,
                find_file_write_risk,
                find_file_deletion_or_dispatch,
                find_link_creation,
                find_inline_interpreter_execution,
                find_dynamic_sensitive_path,
                find_docker_risk,
                find_make_risk,
                find_direct_local_test_runner,
                lambda current: find_privilege_or_install_risk(statement, current),
                lambda current: find_database_risk(statement, current),
                find_cloud_risk,
            ):
                finding = checker(segment)
                if finding is not None:
                    findings.append(finding)

    return findings


def scan_file_write(path: str) -> list[Finding]:
    return file_write_findings(path)


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


def strongest_finding(findings: list[Finding]) -> Finding | None:
    if not findings:
        return None

    for finding in findings:
        if finding.action == "deny":
            return finding

    return findings[0]


def event_name(event: dict[str, Any], fallback: str) -> str:
    value = event.get("hook_event_name")
    if isinstance(value, str) and value:
        return value

    return fallback
