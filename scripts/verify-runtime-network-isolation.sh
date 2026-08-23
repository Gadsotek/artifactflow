#!/usr/bin/env bash

set -euo pipefail

compose=(docker compose --profile test --profile e2e --env-file docker/e2e.env)

container_id() {
    local service="$1"
    local id

    id="$("${compose[@]}" ps -q "$service")"

    if [[ -z "$id" ]]; then
        printf 'Required e2e service is not running: %s\n' "$service" >&2
        exit 1
    fi

    printf '%s' "$id"
}

app_id="$(container_id e2e-app)"
artifact_id="$(container_id e2e-artifact-host)"
app_ip="$(docker inspect --format='{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$app_id")"

if [[ -z "$app_ip" ]]; then
    echo 'Could not resolve the e2e app container IP for callback probes.' >&2
    exit 1
fi

for service in e2e-image-parser e2e-pdf-processor; do
    processor_id="$(container_id "$service")"
    network_mode="$(docker inspect --format='{{.HostConfig.NetworkMode}}' "$processor_id")"

    if [[ "$network_mode" != 'none' ]]; then
        printf '%s must use Docker network mode none, got %s.\n' "$service" "$network_mode" >&2
        exit 1
    fi

    docker exec --env CALLBACK_TARGET="$app_ip" "$processor_id" php -r '
        foreach ([[getenv("CALLBACK_TARGET"), 8000], ["169.254.169.254", 80]] as [$host, $port]) {
            $socket = @fsockopen((string) $host, $port, $errorCode, $errorMessage, 1);
            if (is_resource($socket)) {
                fclose($socket);
                fwrite(STDERR, "Processor callback probe unexpectedly connected to {$host}:{$port}.\n");
                exit(1);
            }
        }
    '
done

docker exec --env CALLBACK_TARGET="$app_ip" "$artifact_id" php -r '
    foreach ([[getenv("CALLBACK_TARGET"), 8000], ["169.254.169.254", 80]] as [$host, $port]) {
        $socket = @fsockopen((string) $host, $port, $errorCode, $errorMessage, 1);
        if (is_resource($socket)) {
            fclose($socket);
            fwrite(STDERR, "Artifact-host callback probe unexpectedly connected to {$host}:{$port}.\n");
            exit(1);
        }
    }

    foreach (["localhost", "app.artifactflow.test"] as $host) {
        $socket = @fsockopen("e2e-edge", 80, $errorCode, $errorMessage, 2);
        if (!is_resource($socket)) {
            fwrite(STDERR, "Artifact-host edge-denial probe could not reach the trusted ingress.\n");
            exit(1);
        }
        fwrite($socket, "GET /up HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);
        if (!is_string($response) || preg_match("/^HTTP\\/1\\.1 403/m", $response) !== 1) {
            fwrite(STDERR, "Artifact host could use the edge as an app callback bridge for {$host}.\n");
            exit(1);
        }
    }
'

echo 'Processor and artifact-host callback probes denied.'
