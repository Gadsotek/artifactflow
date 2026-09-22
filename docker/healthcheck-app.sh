#!/bin/sh
set -eu

role="${APP_RUNTIME_ROLE:-app}"

case "${role}" in
    worker|scheduler)
        # A crashed worker/scheduler exits its container and Docker restarts it, so the
        # useful liveness signal here is a *hung* dependency rather than process death:
        # confirm the storage these roles write to is actually writable (this catches a
        # read-only remount, a full disk, or a bad volume permission that queue:work /
        # schedule:work would otherwise wedge on silently). Kept to a shell write probe
        # with no PHP bootstrap, so it must stay above the interpreter probes below.
        artifact_root="${ARTIFACT_STORAGE_ROOT:-/var/www/html/storage/app/private_artifacts}"
        for storage_dir in "${artifact_root}" /var/www/html/storage/logs /var/www/html/storage/framework; do
            mkdir -p "${storage_dir}" 2>/dev/null || exit 1
            probe="${storage_dir}/.healthcheck-${role}"
            (: > "${probe}") 2>/dev/null || exit 1
            rm -f "${probe}" 2>/dev/null || true
        done
        exit 0
        ;;
    app|artifact-host)
        ;;
    *)
        exit 1
        ;;
esac

php -v >/dev/null
test -f /var/www/html/artisan
php -r 'if (!is_file("/var/www/html/vendor/autoload.php")) { exit(1); }'
port="${PORT:-8000}"

# These probes reach the app over loopback, but in production TrustHosts trusts
# only the hosts of APP_URL and ARTIFACT_URL, so a default 127.0.0.1 Host is
# rejected as a suspicious operation before /up (or /login) can respond. Present
# the configured host parsed from APP_URL; both role env sets carry a trusted
# APP_URL (the app host, and the artifact host on the artifact-host role). Fall
# back to loopback for local/dev, where trusted-host enforcement is disabled.
case "${role}" in
    app)
        HEALTHCHECK_PORT="${port}" php -r '$port = getenv("HEALTHCHECK_PORT") ?: "8000"; $host = parse_url((string) getenv("APP_URL"), PHP_URL_HOST) ?: "127.0.0.1"; $context = stream_context_create(["http" => ["timeout" => 4, "header" => "Host: " . $host]]); exit(@file_get_contents("http://127.0.0.1:" . $port . "/up", false, $context) !== false ? 0 : 1);'
        ;;
    artifact-host)
        test "$(HEALTHCHECK_PORT="${port}" php -r '$port = getenv("HEALTHCHECK_PORT") ?: "8000"; $host = parse_url((string) getenv("APP_URL"), PHP_URL_HOST) ?: "127.0.0.1"; $context = stream_context_create(["http" => ["timeout" => 4, "header" => "Host: " . $host]]); $headers = @get_headers("http://127.0.0.1:" . $port . "/login", false, $context); if ($headers === false) { exit(1); } if (preg_match("/\s([0-9]{3})\s/", $headers[0], $matches) !== 1) { exit(1); } echo $matches[1];')" = "404"
        ;;
esac
