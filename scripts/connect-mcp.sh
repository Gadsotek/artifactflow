#!/usr/bin/env bash
set -euo pipefail

# connect-mcp.sh
#
# Point Claude Desktop, Claude Code, and Codex clients at an ArtifactFlow MCP
# server. The script discovers standard and existing per-instance user configs,
# asks which ones to update, and writes the connection through the `mcp-remote`
# stdio<->HTTP bridge.
#
# Pure bash: needs only standard tools (awk/sed/grep; curl is optional, used for
# a best-effort token check). No app, Docker, make, artisan, node, python, or
# jq required to run it. (Note: the clients themselves need Node.js at runtime,
# since `mcp-remote` runs under npx.)
#
# Mint the af_mcp_ token yourself in the app (Settings > MCP tokens). The token
# is never printed; it is written only into the client config files (chmod 600),
# and existing configs are backed up first and never overwritten blindly.
#
# Non-interactive use: set MCP_URL, MCP_TOKEN, and MCP_TARGETS ("all" or the
# comma-separated target numbers printed by the script) in the environment.
# Repository-level .mcp.json and .codex/config.toml files are intentionally not
# offered because this connection contains a bearer token.
# Options: -h/--help only.

SERVER_NAME="artifactflow"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

die() { printf 'error: %s\n' "$1" >&2; exit 1; }
info() { printf '%s\n' "$1"; }
warn() { printf 'warning: %s\n' "$1" >&2; }

usage() {
    awk '
        !started && /^# connect-mcp\.sh/ { started = 1 }
        started && /^#/ { line = $0; sub(/^# ?/, "", line); print line; next }
        started && !/^#/ { exit }
    ' "$0"
}

case "${1:-}" in
    -h|--help) usage; exit 0 ;;
esac

# --- Gather the two inputs: URL and token ---
URL="${MCP_URL:-}"
if [ -z "$URL" ]; then
    default_url="http://localhost:18080"
    if [ -f "$REPO_ROOT/.env" ]; then
        env_url="$(grep -E '^APP_URL=' "$REPO_ROOT/.env" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' || true)"
        [ -n "$env_url" ] && default_url="$env_url"
    fi
    if [ -t 0 ]; then
        printf 'ArtifactFlow app URL [%s]: ' "$default_url" >&2
        read -r URL
    fi
    [ -n "$URL" ] || URL="$default_url"
fi
URL="${URL%/}"
# Normalise an explicit scheme's case first, so "HTTP://localhost" is recognised
# as a scheme rather than mistaken for a scheme-less host and double-prefixed.
case "$URL" in
    [Hh][Tt][Tt][Pp][Ss]://*) URL="https://${URL#*://}" ;;
    [Hh][Tt][Tt][Pp]://*) URL="http://${URL#*://}" ;;
esac
# mcp-remote needs a full URL with an explicit scheme; a scheme-less value
# (e.g. "localhost:18080") is rejected before the MCP handshake. Default to
# http for loopback hosts (local non-TLS dev) and https for everything else.
case "$URL" in
    http://*|https://*) ;;
    localhost|localhost:*|127.0.0.1|127.0.0.1:*|\[::1\]|\[::1\]:*) URL="http://$URL" ;;
    *) URL="https://$URL" ;;
esac
case "$URL" in
    */mcp) ENDPOINT="$URL" ;;
    *) ENDPOINT="$URL/mcp" ;;
esac
# mcp-remote refuses a plaintext http:// endpoint unless --allow-http is passed.
# Only add it for non-TLS endpoints so an https deployment stays strict.
ALLOW_HTTP=0
case "$ENDPOINT" in http://*) ALLOW_HTTP=1 ;; esac
# Never transmit the bearer token in cleartext to anything but loopback. A
# plaintext http:// endpoint on a real host would leak the token to any network
# observer or reverse proxy (and then bake --allow-http into the client config).
# Loopback dev is the only place plaintext is acceptable. Checked here, before
# the token is ever read or sent.
if [ "$ALLOW_HTTP" = "1" ]; then
    http_authority="${ENDPOINT#http://}"
    http_authority="${http_authority%%/*}"
    # A userinfo ("user[:pass]@host") or a backslash makes the shell's view of the
    # host diverge from a URL client's: a naive "localhost:*" glob matches
    # "localhost:80@remote.example" and "localhost:18080\@evil.example", yet
    # curl/mcp-remote resolve the real host to the right of the "@" (or treat "\"
    # as a separator) and would send the bearer token there in cleartext. Refuse
    # any authority carrying userinfo or a backslash before matching loopback.
    case "$http_authority" in
        *[\\@]*) die "refusing to send the MCP token over plaintext HTTP: authority '$http_authority' carries userinfo or a backslash; use an https:// URL." ;;
    esac
    # Split off an optional port and require it to be purely numeric, so a value
    # like "localhost:evil" cannot ride through on a loopback-prefix match, then
    # validate the bare host against an exact loopback allow-list.
    case "$http_authority" in
        \[*\]) http_host="$http_authority"; http_port="" ;;
        \[*\]:*) http_host="${http_authority%:*}"; http_port="${http_authority##*:}" ;;
        *:*) http_host="${http_authority%:*}"; http_port="${http_authority##*:}" ;;
        *) http_host="$http_authority"; http_port="" ;;
    esac
    case "$http_port" in
        '') ;;
        *[!0-9]*) die "refusing to send the MCP token over plaintext HTTP: authority '$http_authority' has a non-numeric port; use an https:// URL." ;;
    esac
    case "$http_host" in
        localhost|127.0.0.1|\[::1\]) ;;
        *) die "refusing to send the MCP token over plaintext HTTP to non-loopback host '$http_host'; use an https:// URL." ;;
    esac
fi

# --- Discover user-level client configs and choose explicit targets ---
TARGET_KINDS=()
TARGET_LABELS=()
TARGET_PATHS=()
TARGET_SELECTED=()

add_target() {
    local kind="$1" label="$2" path="$3" index=0
    while [ "$index" -lt "${#TARGET_PATHS[@]}" ]; do
        [ "${TARGET_PATHS[$index]}" = "$path" ] && return 0
        index=$((index + 1))
    done

    TARGET_KINDS[${#TARGET_KINDS[@]}]="$kind"
    TARGET_LABELS[${#TARGET_LABELS[@]}]="$label"
    TARGET_PATHS[${#TARGET_PATHS[@]}]="$path"
    TARGET_SELECTED[${#TARGET_SELECTED[@]}]=0
}

discover_targets() {
    local cfg instance active_claude_config active_codex_home index base_count profile

    case "$(uname -s)" in
        Darwin)
            add_target "claude-desktop" "Claude Desktop (standard)" \
                "$HOME/Library/Application Support/Claude/claude_desktop_config.json"
            ;;
        Linux)
            add_target "claude-desktop" "Claude Desktop (standard)" \
                "$HOME/.config/Claude/claude_desktop_config.json"
            ;;
    esac

    # Existing Desktop channels or separately installed builds commonly use a
    # sibling Claude* application-support directory. Only bounded known config
    # locations are scanned; the rest of the home directory is never searched.
    for cfg in \
        "$HOME"/Library/Application\ Support/Claude*/claude_desktop_config.json \
        "$HOME"/.config/Claude*/claude_desktop_config.json \
        "$HOME"/.config/claude*/claude_desktop_config.json; do
        [ -f "$cfg" ] || continue
        instance="$(basename "$(dirname "$cfg")")"
        add_target "claude-desktop" "Claude Desktop ($instance)" "$cfg"
    done

    if [ -n "${CLAUDE_CONFIG_DIR:-}" ]; then
        active_claude_config="$CLAUDE_CONFIG_DIR/.claude.json"
        add_target "claude-code" "Claude Code (active CLAUDE_CONFIG_DIR)" "$active_claude_config"
        [ -f "$HOME/.claude.json" ] && add_target "claude-code" "Claude Code (default)" "$HOME/.claude.json"
    else
        active_claude_config="$HOME/.claude.json"
        add_target "claude-code" "Claude Code (default)" "$active_claude_config"
    fi

    # CLAUDE_CONFIG_DIR is often used as ~/.claude-work or ~/.claude-personal.
    # Each such instance stores its user MCP configuration inside that directory.
    for cfg in "$HOME"/.claude*/.claude.json; do
        [ -f "$cfg" ] || continue
        instance="$(basename "$(dirname "$cfg")")"
        add_target "claude-code" "Claude Code ($instance)" "$cfg"
    done

    active_codex_home="${CODEX_HOME:-$HOME/.codex}"
    add_target "codex" "Codex (active CODEX_HOME)" "$active_codex_home/config.toml"
    if [ "$active_codex_home" != "$HOME/.codex" ] && [ -f "$HOME/.codex/config.toml" ]; then
        add_target "codex" "Codex (default)" "$HOME/.codex/config.toml"
    fi

    # CODEX_HOME supports parallel installations/accounts. Discover conventional
    # sibling homes and their existing profile overlay files.
    for cfg in "$HOME"/.codex*/config.toml; do
        [ -f "$cfg" ] || continue
        instance="$(basename "$(dirname "$cfg")")"
        add_target "codex" "Codex ($instance)" "$cfg"
    done

    base_count="${#TARGET_PATHS[@]}"
    index=0
    while [ "$index" -lt "$base_count" ]; do
        if [ "${TARGET_KINDS[$index]}" = "codex" ]; then
            for profile in "$(dirname "${TARGET_PATHS[$index]}")"/*.config.toml; do
                [ -f "$profile" ] || continue
                instance="$(basename "$profile" .config.toml)"
                add_target "codex-profile" "Codex profile ($instance)" "$profile"
            done
        fi
        index=$((index + 1))
    done
}

print_targets() {
    local index=0 state
    info ""
    info "Discovered MCP client config targets:"
    while [ "$index" -lt "${#TARGET_PATHS[@]}" ]; do
        state="new"
        [ -f "${TARGET_PATHS[$index]}" ] && state="existing"
        printf '  %d) %s\n     %s [%s]\n' \
            "$((index + 1))" "${TARGET_LABELS[$index]}" "${TARGET_PATHS[$index]}" "$state"
        index=$((index + 1))
    done
}

select_targets() {
    local selection="${MCP_TARGETS:-}" remaining item target_index index=0 selected_count=0

    print_targets
    if [ -z "$selection" ]; then
        if [ -t 0 ]; then
            printf 'Configure which targets? Enter comma-separated numbers or "all": ' >&2
            read -r selection
        else
            die "no target selection provided (set MCP_TARGETS=all or a comma-separated list of target numbers)"
        fi
    fi

    selection="$(printf '%s' "$selection" | tr -d '[:space:]')"
    [ -n "$selection" ] || die "no MCP config targets selected"

    if [ "$selection" = "all" ]; then
        while [ "$index" -lt "${#TARGET_SELECTED[@]}" ]; do
            TARGET_SELECTED[$index]=1
            index=$((index + 1))
        done
        return 0
    fi

    remaining="$selection,"
    while [ -n "$remaining" ]; do
        item="${remaining%%,*}"
        remaining="${remaining#*,}"
        case "$item" in
            ''|*[!0-9]*) die "invalid MCP target '$item'; use target numbers or 'all'" ;;
        esac
        target_index=$((item - 1))
        if [ "$target_index" -lt 0 ] || [ "$target_index" -ge "${#TARGET_PATHS[@]}" ]; then
            die "MCP target number '$item' is out of range"
        fi
        TARGET_SELECTED[$target_index]=1
    done

    index=0
    while [ "$index" -lt "${#TARGET_SELECTED[@]}" ]; do
        [ "${TARGET_SELECTED[$index]}" = "1" ] && selected_count=$((selected_count + 1))
        index=$((index + 1))
    done
    [ "$selected_count" -gt 0 ] || die "no MCP config targets selected"
}

discover_targets
[ "${#TARGET_PATHS[@]}" -gt 0 ] || die "no supported Claude or Codex user config targets found"
select_targets

TOKEN="${MCP_TOKEN:-}"
if [ -z "$TOKEN" ]; then
    [ -t 0 ] || die "no token provided (set MCP_TOKEN or run interactively)"
    printf 'MCP token (af_mcp_..., input hidden): ' >&2
    stty -echo 2>/dev/null || true
    read -r TOKEN
    stty echo 2>/dev/null || true
    printf '\n' >&2
fi
[ -n "$TOKEN" ] || die "no token entered"
case "$TOKEN" in af_mcp_*) ;; *) warn "token does not start with 'af_mcp_'; continuing anyway." ;; esac

# --- Best-effort token check (skipped if curl is missing; never fatal) ---
if command -v curl >/dev/null 2>&1; then
    info "Checking $ENDPOINT (best-effort)..."
    # Feed the Authorization header via a stdin curl config (-K -) so the token
    # never appears in the process argv (ps/procfs-visible for the request time).
    code="$(
        printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
            -X POST "$ENDPOINT" \
            -K - \
            -H 'Content-Type: application/json' \
            -H 'Accept: application/json' \
            -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' 2>/dev/null || true
    )"
    [ -n "$code" ] || code="000"
    case "$code" in
        200) info "OK: endpoint reachable and token accepted." ;;
        000) warn "could not reach $ENDPOINT (offline or wrong URL?) — writing config anyway." ;;
        401|403) warn "endpoint returned $code — token may be invalid/expired — writing config anyway." ;;
        3??) warn "endpoint redirected ($code) — token may be missing/invalid — writing config anyway." ;;
        404) warn "endpoint returned 404 — is that the app origin, not the artifact host? — writing config anyway." ;;
        *) warn "endpoint returned HTTP $code — writing config anyway." ;;
    esac
fi

# JSON-escape a value for embedding in a double-quoted JSON/TOML string.
json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
ESC_ENDPOINT="$(json_escape "$ENDPOINT")"
ESC_BEARER="$(json_escape "Bearer $TOKEN")"

backup_if_present() {
    [ -f "$1" ] || return 0
    cp "$1" "$1.bak.$(date +%s)"
    chmod 600 "$1".bak.* 2>/dev/null || true
}

# The server entry, pretty-printed as a JSON value (indented for a 4-space nest).
# Takes an optional AUTH_HEADER value so the real token is used when writing the
# config file but a redacted placeholder can be shown in on-screen fallbacks.
claude_entry() {
    local bearer="${1:-$ESC_BEARER}"
    local allow_line=""
    [ "$ALLOW_HTTP" = "1" ] && allow_line='
        "--allow-http",'
    cat <<JSON
{
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "$ESC_ENDPOINT",$allow_line
        "--header",
        "Authorization:\${AUTH_HEADER}"
      ],
      "env": {
        "AUTH_HEADER": "$bearer"
      }
    }
JSON
}

# String-aware brace/bracket balance check: exits 0 if the file is a balanced
# JSON-ish object (depth returns to zero, quotes closed), non-zero otherwise.
json_balanced() {
    awk '
        { s = s $0 "\n" }
        END {
            n = length(s); depth = 0; instr = 0; esc = 0
            for (i = 1; i <= n; i++) {
                c = substr(s, i, 1)
                if (instr) {
                    if (esc) esc = 0
                    else if (c == "\\") esc = 1
                    else if (c == "\"") instr = 0
                    continue
                }
                if (c == "\"") { instr = 1; continue }
                if (c == "{" || c == "[") depth++
                else if (c == "}" || c == "]") { depth--; if (depth < 0) exit 1 }
            }
            exit (depth == 0 && instr == 0) ? 0 : 1
        }
    ' "$1"
}

# Merge our entry into a Claude Desktop or Claude Code JSON config (string-aware
# awk). Only a root-level mcpServers key is considered: Claude Code also stores
# project-local mcpServers deeper in ~/.claude.json, and those must stay scoped to
# their project. Reads the existing file (may be empty/missing) and prints the
# merged JSON to stdout.
claude_merge() {
    local src="$1" name="$2" entry="$3"
    NAME="$name" ENTRY="$entry" awk '
        function is_ws(c) { return (c == " " || c == "\t" || c == "\n" || c == "\r") }
        function firstnonws(str,   i, n, c) {
            n = length(str)
            for (i = 1; i <= n; i++) { c = substr(str, i, 1); if (!is_ws(c)) return c }
            return ""
        }
        function idxfrom(str, needle, from,   p) {
            p = index(substr(str, from), needle)
            return (p == 0) ? 0 : p + from - 1
        }
        function key_at_depth(str, wanted, wanted_depth,   i, j, k, n, depth, c, token, esc) {
            n = length(str); depth = 0
            for (i = 1; i <= n; i++) {
                c = substr(str, i, 1)
                if (c == "{") { depth++; continue }
                if (c == "}") { depth--; continue }
                if (c != "\"") continue

                token = ""; esc = 0
                for (j = i + 1; j <= n; j++) {
                    c = substr(str, j, 1)
                    if (esc) { token = token c; esc = 0; continue }
                    if (c == "\\") { esc = 1; continue }
                    if (c == "\"") break
                    token = token c
                }
                if (depth == wanted_depth && token == wanted) {
                    k = j + 1
                    while (k <= n && is_ws(substr(str, k, 1))) k++
                    if (substr(str, k, 1) == ":") return i
                }
                i = j
            }
            return 0
        }
        function matchbrace(str, open,   i, n, depth, instr, esc, c) {
            n = length(str); depth = 0; instr = 0; esc = 0
            for (i = open; i <= n; i++) {
                c = substr(str, i, 1)
                if (instr) {
                    if (esc) esc = 0; else if (c == "\\") esc = 1; else if (c == "\"") instr = 0
                    continue
                }
                if (c == "\"") { instr = 1; continue }
                if (c == "{") depth++
                else if (c == "}") { depth--; if (depth == 0) return i }
            }
            return 0
        }
        function remove_entry(body, name,   p, br, e, after, before) {
            p = key_at_depth(body, name, 0)
            if (p == 0) return body
            br = idxfrom(body, "{", p)
            if (br == 0) return body
            e = matchbrace(body, br)
            if (e == 0) return body
            after = e + 1
            while (after <= length(body) && is_ws(substr(body, after, 1))) after++
            if (substr(body, after, 1) == ",")
                return substr(body, 1, p - 1) substr(body, after + 1)
            before = p - 1
            while (before >= 1 && is_ws(substr(body, before, 1))) before--
            if (before >= 1 && substr(body, before, 1) == ",") p = before
            return substr(body, 1, p - 1) substr(body, e + 1)
        }
        { s = s $0 "\n" }
        END {
            name = ENVIRON["NAME"]; entry = ENVIRON["ENTRY"]
            if (firstnonws(s) == "") {
                printf "{\n  \"mcpServers\": {\n    \"%s\": %s\n  }\n}\n", name, entry
                exit
            }
            mspos = key_at_depth(s, "mcpServers", 1)
            if (mspos == 0) {
                root = index(s, "{")
                if (root == 0) {  # not an object; replace wholesale
                    printf "{\n  \"mcpServers\": {\n    \"%s\": %s\n  }\n}\n", name, entry
                    exit
                }
                if (firstnonws(substr(s, root + 1)) == "}")
                    ins = "\n  \"mcpServers\": {\n    \"" name "\": " entry "\n  }\n"
                else
                    ins = "\n  \"mcpServers\": {\n    \"" name "\": " entry "\n  },"
                printf "%s", substr(s, 1, root) ins substr(s, root + 1)
                exit
            }
            ob = idxfrom(s, "{", mspos)
            cb = matchbrace(s, ob)
            if (ob == 0 || cb == 0) { printf "%s", s; exit }  # give up safely; validator will catch
            body = substr(s, ob + 1, cb - ob - 1)
            previous = ""
            while (index(body, "\"" name "\"") != 0 && body != previous) {
                previous = body
                body = remove_entry(body, name)
            }
            if (firstnonws(body) == "")
                newbody = "\n    \"" name "\": " entry "\n  "
            else
                newbody = "\n    \"" name "\": " entry ",\n" body
            printf "%s", substr(s, 1, ob) newbody substr(s, cb)
        }
    ' "$src"
}

configure_claude_config() {
    local label="$1" cfg="$2" restart_note="$3" entry tmp source
    [ -L "$cfg" ] && warn "$label: refusing to replace symlink $cfg" && return 0
    entry="$(claude_entry)"
    mkdir -p "$(dirname "$cfg")"
    backup_if_present "$cfg"

    tmp="$(mktemp)"
    source="$cfg"
    [ -f "$source" ] || source="/dev/null"
    claude_merge "$source" "$SERVER_NAME" "$entry" > "$tmp" 2>/dev/null || true

    if [ -s "$tmp" ] && json_balanced "$tmp" && grep -q "\"$SERVER_NAME\"" "$tmp"; then
        cat "$tmp" > "$cfg"
        chmod 600 "$cfg"
        rm -f "$tmp"
        info "$label configured: $cfg$restart_note"
    else
        rm -f "$tmp"
        warn "could not safely merge $cfg — it was left untouched."
        info "Add this under \"mcpServers\" in that file yourself, replacing the"
        info "placeholder with your 'Bearer af_mcp_...' token (kept off-screen here):"
        printf '    "%s": %s\n' "$SERVER_NAME" "$(claude_entry 'Bearer af_mcp_YOUR_TOKEN_HERE')" >&2
    fi
}

configure_codex_config() {
    local label="$1" cfg="$2"
    local begin="# >>> $SERVER_NAME mcp (managed by scripts/connect-mcp.sh) >>>"
    local end="# <<< $SERVER_NAME mcp (managed by scripts/connect-mcp.sh) <<<"
    local block tmp allow_arg=""
    [ "$ALLOW_HTTP" = "1" ] && allow_arg=', "--allow-http"'
    block="$(printf '%s\n[mcp_servers.%s]\ncommand = "npx"\nargs = ["-y", "mcp-remote", "%s"%s, "--header", "Authorization:${AUTH_HEADER}"]\nenv = { AUTH_HEADER = "%s" }\n%s' \
        "$begin" "$SERVER_NAME" "$ESC_ENDPOINT" "$allow_arg" "$ESC_BEARER" "$end")"

    [ -L "$cfg" ] && warn "$label: refusing to replace symlink $cfg" && return 0
    mkdir -p "$(dirname "$cfg")"
    backup_if_present "$cfg"

    tmp="$(mktemp)"
    if [ -f "$cfg" ]; then
        awk -v b="$begin" -v e="$end" '
            function table_name(line, name) {
                name = line
                sub(/^[[:space:]]*\[/, "", name)
                sub(/\][[:space:]]*(#.*)?$/, "", name)
                return name
            }
            function is_table(line) {
                return line ~ /^[[:space:]]*\[[^]]+\][[:space:]]*(#.*)?$/
            }
            function is_artifactflow_table(line, name) {
                if (!is_table(line)) return 0
                name = table_name(line)
                return name == "mcp_servers.artifactflow" \
                    || name ~ /^mcp_servers\.artifactflow\./ \
                    || name == "mcp_servers.\"artifactflow\"" \
                    || name ~ /^mcp_servers\.\"artifactflow\"\./ \
                    || name == "mcp_servers.\047artifactflow\047" \
                    || name ~ /^mcp_servers\.\047artifactflow\047\./
            }
            $0 == b { skip = 1; next }
            skip && $0 == e { skip = 0; next }
            skip { next }
            legacy && is_artifactflow_table($0) { next }
            legacy && is_table($0) { legacy = 0 }
            legacy { next }
            is_artifactflow_table($0) { legacy = 1; next }
            { print }
        ' "$cfg" > "$tmp"
        [ -s "$tmp" ] && [ "$(tail -c1 "$tmp")" != "" ] && printf '\n' >> "$tmp"
        printf '\n' >> "$tmp"
    fi
    printf '%s\n' "$block" >> "$tmp"

    cat "$tmp" > "$cfg"
    chmod 600 "$cfg"
    rm -f "$tmp"
    info "$label configured: $cfg"
}

index=0
while [ "$index" -lt "${#TARGET_PATHS[@]}" ]; do
    if [ "${TARGET_SELECTED[$index]}" = "1" ]; then
        case "${TARGET_KINDS[$index]}" in
            claude-desktop)
                configure_claude_config "${TARGET_LABELS[$index]}" "${TARGET_PATHS[$index]}" \
                    " (restart Claude Desktop to load it)."
                ;;
            claude-code)
                configure_claude_config "${TARGET_LABELS[$index]}" "${TARGET_PATHS[$index]}" \
                    " (restart active Claude Code sessions to load it)."
                ;;
            codex|codex-profile)
                configure_codex_config "${TARGET_LABELS[$index]}" "${TARGET_PATHS[$index]}"
                ;;
        esac
    fi
    index=$((index + 1))
done

info ""
info "Done. MCP server '$SERVER_NAME' -> $ENDPOINT"
info "The token lives only in the client config file(s) (chmod 600)."
command -v npx >/dev/null 2>&1 || warn "'npx' not found — install Node.js so the clients can launch mcp-remote."
