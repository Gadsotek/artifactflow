#!/usr/bin/env bash

set -euo pipefail

image="${1:?usage: timeout-harness.sh IMAGE}"
container_name="artifactflow-pdf-timeout-${RANDOM}-$$"

cleanup() {
    docker rm -f "${container_name}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --name "${container_name}" --init --network none --read-only \
    --cap-drop ALL --security-opt no-new-privileges --pids-limit 32 \
    --memory 512m --cpus 1 --tmpfs /tmp:rw,noexec,nosuid,size=32m \
    "${image}" hang-for-timeout-test >/dev/null

deadline=$((SECONDS + 2))
while [[ "$(docker inspect --format='{{.State.Running}}' "${container_name}")" == "true" ]]; do
    if ((SECONDS >= deadline)); then
        break
    fi
    sleep 0.1
done

if [[ "$(docker inspect --format='{{.State.Running}}' "${container_name}")" != "true" ]]; then
    echo "hung processor exited before the timeout harness intervened" >&2
    exit 1
fi

docker kill --signal KILL "${container_name}" >/dev/null
exit_status="$(docker wait "${container_name}")"
if [[ "${exit_status}" != "137" ]]; then
    echo "expected timeout kill status 137, got ${exit_status}" >&2
    exit 1
fi

echo "pdf-processor-spike hard timeout stopped hung processor"
