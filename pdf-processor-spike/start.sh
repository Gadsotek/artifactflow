#!/bin/sh

set -eu

if [ "${PHP_CLI_SERVER_WORKERS:-1}" != "1" ]; then
    echo "ArtifactFlow PDF processor must stay at one worker per memory-limited container." >&2
    exit 1
fi

unset PHP_CLI_SERVER_WORKERS

/usr/local/bin/artifactflow-process-deny --self-test

port="${PORT:-8080}"
socket_path="${PDF_PROCESSOR_SOCKET_PATH:-/run/artifactflow/pdf-processor/processor.sock}"

case "$port" in
    ''|*[!0-9]*|??????*)
        echo "ArtifactFlow PDF processor PORT must be an integer between 1 and 65535." >&2
        exit 1
        ;;
esac

if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "ArtifactFlow PDF processor PORT must be an integer between 1 and 65535." >&2
    exit 1
fi

case "$socket_path" in
    /*) ;;
    *)
        echo "ArtifactFlow PDF processor socket path must be absolute." >&2
        exit 1
        ;;
esac

socket_directory="${socket_path%/*}"

if [ ! -d "$socket_directory" ] || [ ! -w "$socket_directory" ]; then
    echo "ArtifactFlow PDF processor socket directory must exist and be writable." >&2
    exit 1
fi

rm -f "$socket_path"

php \
    -d display_errors=0 \
    -d log_errors=0 \
    -d expose_php=0 \
    -d memory_limit=64M \
    -d max_execution_time=15 \
    -d post_max_size=17M \
    -d upload_max_filesize=17M \
    -S "127.0.0.1:${port}" \
    -t /srv/pdf-processor-spike/public \
    /srv/pdf-processor-spike/public/index.php &
server_pid=$!

socat "UNIX-LISTEN:${socket_path},fork,mode=0660" "TCP:127.0.0.1:${port}" &
relay_pid=$!

cleanup() {
    kill "$relay_pid" "$server_pid" 2>/dev/null || true
    wait "$relay_pid" "$server_pid" 2>/dev/null || true
    rm -f "$socket_path"
}

terminate() {
    exit 143
}

trap cleanup EXIT
trap terminate HUP INT TERM

while kill -0 "$server_pid" 2>/dev/null && kill -0 "$relay_pid" 2>/dev/null; do
    sleep 1
done

exit 1
