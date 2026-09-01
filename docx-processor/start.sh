#!/bin/sh

set -eu

if [ "${PHP_CLI_SERVER_WORKERS:-1}" != "1" ]; then
    echo "DOCX processor must stay at one HTTP worker." >&2
    exit 1
fi
unset PHP_CLI_SERVER_WORKERS

socket_path="${DOCX_PROCESSOR_SOCKET_PATH:-/run/artifactflow/docx-processor/processor.sock}"
case "$socket_path" in
    /*.sock) ;;
    *) echo "DOCX processor socket path must be an absolute .sock path." >&2; exit 1 ;;
esac

socket_directory="${socket_path%/*}"
if [ ! -d "$socket_directory" ] || [ ! -w "$socket_directory" ]; then
    echo "DOCX processor socket directory must exist and be writable." >&2
    exit 1
fi

rm -f "$socket_path"

php -d display_errors=0 -d log_errors=0 -d expose_php=0 -d memory_limit=256M \
    -d max_execution_time=45 -d post_max_size=17M \
    -S 127.0.0.1:8080 -t /srv/docx-processor/public /srv/docx-processor/public/index.php &
server_pid=$!
socat "UNIX-LISTEN:${socket_path},fork,mode=0666" TCP:127.0.0.1:8080 &
relay_pid=$!

cleanup() {
    kill "$relay_pid" "$server_pid" 2>/dev/null || true
    wait "$relay_pid" "$server_pid" 2>/dev/null || true
    rm -f "$socket_path"
}

trap cleanup EXIT HUP INT TERM
while kill -0 "$server_pid" 2>/dev/null && kill -0 "$relay_pid" 2>/dev/null; do sleep 1; done
exit 1
