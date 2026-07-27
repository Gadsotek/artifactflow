#!/bin/sh

set -eu

if [ "${PHP_CLI_SERVER_WORKERS:-1}" != "1" ]; then
    echo "ArtifactFlow image parser must stay at one worker per memory-limited container." >&2
    exit 1
fi

unset PHP_CLI_SERVER_WORKERS

port="${PORT:-8080}"

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

exec php \
    -d display_errors=0 \
    -d log_errors=0 \
    -d expose_php=0 \
    -d memory_limit=448M \
    -d max_execution_time=15 \
    -d post_max_size=6M \
    -d upload_max_filesize=6M \
    -S "0.0.0.0:${port}" \
    -t /srv/image-parser/public \
    /srv/image-parser/public/index.php
