#!/bin/sh

set -eu

if [ "${PHP_CLI_SERVER_WORKERS:-1}" != "1" ]; then
    echo "ArtifactFlow image parser must stay at one worker per memory-limited container." >&2
    exit 1
fi

unset PHP_CLI_SERVER_WORKERS

port="${PORT:-8080}"
socket_path="${IMAGE_PARSER_SOCKET_PATH:-/run/artifactflow/image-parser/parser.sock}"

case "$port" in
    ''|*[!0-9]*|??????*)
        echo "ArtifactFlow image parser PORT must be an integer between 1 and 65535." >&2
        exit 1
        ;;
esac

if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo "ArtifactFlow image parser PORT must be an integer between 1 and 65535." >&2
    exit 1
fi

case "$socket_path" in
    /*) ;;
    *)
        echo "ArtifactFlow image parser socket path must be absolute." >&2
        exit 1
        ;;
esac

socket_directory="${socket_path%/*}"

if [ ! -d "$socket_directory" ] || [ ! -w "$socket_directory" ]; then
    echo "ArtifactFlow image parser socket directory must exist and be writable." >&2
    exit 1
fi

rm -f "$socket_path"

php \
    -d display_errors=0 \
    -d log_errors=0 \
    -d expose_php=0 \
    -d memory_limit=448M \
    -d max_execution_time=15 \
    -d post_max_size=6M \
    -d upload_max_filesize=6M \
    -S "127.0.0.1:${port}" \
    -t /srv/image-parser/public \
    /srv/image-parser/public/index.php &
server_pid=$!

socat "UNIX-LISTEN:${socket_path},fork,mode=0666" "TCP:127.0.0.1:${port}" &
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
