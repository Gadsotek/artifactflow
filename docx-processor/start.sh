#!/bin/sh

set -eu

if [ "${PHP_CLI_SERVER_WORKERS:-1}" != "1" ]; then
    echo "DOCX processor requires exactly one HTTP worker." >&2
    exit 1
fi

socket_path="${DOCX_PROCESSOR_SOCKET_PATH:-/run/artifactflow/docx-processor/processor.sock}"
case "$socket_path" in
    /*.sock) ;;
    *) echo "DOCX processor socket path must be an absolute .sock path." >&2; exit 1 ;;
esac
case "$socket_path" in
    *[!A-Za-z0-9_./-]*|*/../*|*/./*|*/..|*/.)
        echo "DOCX processor socket path contains an unsafe component." >&2
        exit 1
        ;;
esac

socket_directory="${socket_path%/*}"
if [ ! -d "$socket_directory" ] || [ ! -w "$socket_directory" ]; then
    echo "DOCX processor socket directory must exist and be writable." >&2
    exit 1
fi

runtime_directory=/tmp/artifactflow-docx-runtime
mkdir -p "$runtime_directory/config" "$runtime_directory/data"
rm -f "$socket_path"

export DOCX_PROCESSOR_BIND="unix/${socket_path}|0660"
export XDG_CONFIG_HOME="$runtime_directory/config"
export XDG_DATA_HOME="$runtime_directory/data"

frankenphp run --config /srv/docx-processor/Caddyfile --adapter caddyfile &
server_pid=$!

for _attempt in $(seq 1 50); do
    if [ -S "$socket_path" ]; then
        chmod 0660 "$socket_path"
        break
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
        wait "$server_pid" || true
        echo "DOCX processor FrankenPHP server failed to start." >&2
        exit 1
    fi
    sleep 0.1
done
if [ ! -S "$socket_path" ]; then
    echo "DOCX processor public socket was not created." >&2
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    exit 1
fi

cleanup() {
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
    rm -f "$socket_path"
}

trap cleanup EXIT HUP INT TERM
wait "$server_pid"
