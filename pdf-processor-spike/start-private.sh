#!/bin/sh

set -eu

if [ "${PHP_CLI_SERVER_WORKERS:-1}" != "1" ]; then
    echo "ArtifactFlow PDF processor must stay at one worker per memory-limited container." >&2
    exit 1
fi

unset PHP_CLI_SERVER_WORKERS

if [ -n "${PDF_PROCESSOR_SOCKET_PATH:-}" ]; then
    echo "ArtifactFlow private-network PDF processor must not configure a Unix socket path." >&2
    exit 1
fi

port="${PORT:-8080}"

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

/usr/local/bin/artifactflow-network-deny --self-test

exec /usr/local/bin/artifactflow-network-deny \
    php \
    -d display_errors=0 \
    -d log_errors=0 \
    -d expose_php=0 \
    -d memory_limit=64M \
    -d max_execution_time=15 \
    -d post_max_size=17M \
    -d upload_max_filesize=17M \
    -S "0.0.0.0:${port}" \
    -t /srv/pdf-processor-spike/public \
    /srv/pdf-processor-spike/public/index.php
