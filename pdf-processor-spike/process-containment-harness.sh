#!/bin/sh

set -eu

image="${1:-}"

if [ -z "$image" ]; then
    echo "PDF processor containment probe requires an image tag." >&2
    exit 2
fi

repository_root="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
probe="$repository_root/pdf-processor-spike/process-containment-test.php"

output="$(docker run --rm --network none --read-only --cap-drop ALL \
    --security-opt no-new-privileges --pids-limit 32 --memory 512m --cpus 1 \
    --tmpfs /tmp:rw,noexec,nosuid,size=32m \
    --mount "type=bind,source=$probe,target=/probe.php,readonly" \
    --entrypoint php "$image" /probe.php)"

printf '%s\n' "$output"

if [ "$output" != '{"descendant_alive_after_timeout":false,"later_input_observed":false}' ]; then
    echo "PDF processor descendant containment probe failed." >&2
    exit 1
fi

echo "PDF processor descendant containment probe passed."
