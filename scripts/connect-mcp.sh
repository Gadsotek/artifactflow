#!/usr/bin/env bash
set -euo pipefail

# connect-mcp.sh
#
# Point Claude Desktop and the Codex CLI at an ArtifactFlow MCP server. Run it,
# answer two prompts (the app URL and your MCP token), and it writes the
# connection into each client's config using the `mcp-remote` stdio<->HTTP
# bridge.
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
# Non-interactive use: set MCP_URL and MCP_TOKEN in the environment.
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
            -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' 2>/dev/null || echo "000"
    )"
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

# Merge our entry into a Claude Desktop JSON config (string-aware awk). Reads the
# existing file (may be empty/missing) and prints the merged JSON to stdout.
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
        function remove_entry(body, name,   key, p, br, e, after, before, endp) {
            key = "\"" name "\""
            p = index(body, key)
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
            mspos = index(s, "\"mcpServers\"")
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

configure_claude() {
    local cfg
    case "$(uname -s)" in
        Darwin) cfg="$HOME/Library/Application Support/Claude/claude_desktop_config.json" ;;
        Linux) cfg="$HOME/.config/Claude/claude_desktop_config.json" ;;
        *) warn "Claude Desktop: unsupported OS; add the entry to your config manually."; return ;;
    esac

    local entry tmp
    entry="$(claude_entry)"
    mkdir -p "$(dirname "$cfg")"
    backup_if_present "$cfg"

    tmp="$(mktemp)"
    [ -f "$cfg" ] || : > "$cfg"
    claude_merge "$cfg" "$SERVER_NAME" "$entry" > "$tmp" 2>/dev/null || true

    if [ -s "$tmp" ] && json_balanced "$tmp" && grep -q "\"$SERVER_NAME\"" "$tmp"; then
        cat "$tmp" > "$cfg"
        chmod 600 "$cfg"
        rm -f "$tmp"
        info "Claude Desktop configured: $cfg (restart Claude Desktop to load it)."
    else
        rm -f "$tmp"
        warn "could not safely merge $cfg — it was left untouched."
        info "Add this under \"mcpServers\" in that file yourself, replacing the"
        info "placeholder with your 'Bearer af_mcp_...' token (kept off-screen here):"
        printf '    "%s": %s\n' "$SERVER_NAME" "$(claude_entry 'Bearer af_mcp_YOUR_TOKEN_HERE')" >&2
    fi
}

configure_codex() {
    local cfg="${CODEX_HOME:-$HOME/.codex}/config.toml"
    local begin="# >>> $SERVER_NAME mcp (managed by scripts/connect-mcp.sh) >>>"
    local end="# <<< $SERVER_NAME mcp (managed by scripts/connect-mcp.sh) <<<"
    local block tmp allow_arg=""
    [ "$ALLOW_HTTP" = "1" ] && allow_arg=', "--allow-http"'
    block="$(printf '%s\n[mcp_servers.%s]\ncommand = "npx"\nargs = ["-y", "mcp-remote", "%s"%s, "--header", "Authorization:${AUTH_HEADER}"]\nenv = { AUTH_HEADER = "%s" }\n%s' \
        "$begin" "$SERVER_NAME" "$ESC_ENDPOINT" "$allow_arg" "$ESC_BEARER" "$end")"

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
    info "Codex configured: $cfg"
}

configure_claude
configure_codex

info ""
info "Done. MCP server '$SERVER_NAME' -> $ENDPOINT"
info "The token lives only in the client config file(s) (chmod 600)."
command -v npx >/dev/null 2>&1 || warn "'npx' not found — install Node.js so the clients can launch mcp-remote."
