#!/usr/bin/env bash
set -euo pipefail

# Only this invocation's images and builder may be removed. Never prune the
# daemon or reuse a builder/tag belonging to the development stack.
make_command="${1:-make}"
run_name="artifactflow-quality-$(openssl rand -hex 12)"
builder_created=0
image_refs=(
    "$run_name:app"
    "$run_name:image-parser"
    "$run_name:pdf-service"
    "$run_name:pdf-private"
    "$run_name:xlsx"
    "$run_name:docx"
)

cleanup() {
    local status=$?
    local cleanup_failed=0
    local image_ref image_id
    trap - EXIT
    trap '' INT TERM HUP

    if [ "$builder_created" -eq 1 ]; then
        for image_ref in "${image_refs[@]}"; do
            if ! image_id="$(docker image ls --quiet --no-trunc --filter "reference=$image_ref")"; then
                echo "Cleanup could not inspect $image_ref; leaving it for review." >&2
                cleanup_failed=1
                continue
            fi
            if [ -n "$image_id" ] && ! docker image rm --no-prune "$image_ref"; then
                echo "Cleanup could not remove $image_ref; leaving it for review." >&2
                cleanup_failed=1
            fi
        done

        # Buildx removes only this builder's container and cache state. Keeping
        # shared builders intact protects other applications' build resources.
        if ! docker buildx rm "$run_name"; then
            echo "Cleanup could not remove builder $run_name and its cache." >&2
            cleanup_failed=1
        fi
    fi

    if [ "$status" -eq 0 ] && [ "$cleanup_failed" -ne 0 ]; then
        status=1
    fi
    exit "$status"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

echo "Production verification uses temporary images and builder $run_name."
docker buildx create --name "$run_name" --driver docker-container
builder_created=1

make_options=(
    "DOCKER_BUILD=docker buildx build --builder $run_name --load"
    "DOCKER_BUILD_CACHE_ARGS="
    "PRODUCTION_IMAGE=${image_refs[0]}"
    "IMAGE_PARSER_IMAGE=${image_refs[1]}"
    "PDF_PROCESSOR_SERVICE_IMAGE=${image_refs[2]}"
    "PDF_PROCESSOR_PRIVATE_SERVICE_IMAGE=${image_refs[3]}"
    "XLSX_PROCESSOR_SERVICE_IMAGE=${image_refs[4]}"
    "DOCX_PROCESSOR_IMAGE=${image_refs[5]}"
)

"$make_command" build-prod "${make_options[@]}"
"$make_command" scan-image "${make_options[@]}"
