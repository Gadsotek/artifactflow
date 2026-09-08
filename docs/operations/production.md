# Production deployment

[Operations](../OPERATIONS.md) · [Release checklist](../../RELEASE-CHECKLIST.md)

Use the production images with your own orchestration, TLS, PostgreSQL, secrets,
and persistent storage. The bundled Compose stack is local-only.

## Roles and processes

| Role | `APP_RUNTIME_ROLE` | Container command |
| --- | --- | --- |
| App | `app` | Default HTTP entrypoint |
| Artifact host | `artifact-host` | Default HTTP entrypoint |
| Worker | `worker` | `sh /var/www/html/docker/start-worker.sh` |
| Scheduler | `scheduler` | `sh /var/www/html/docker/start-scheduler.sh` |

Setting a worker/scheduler role does not change the default HTTP process.
Override its command too. The worker delivers queued mail; the scheduler
dispatches the event journal and prunes expired records. Keep both running.

App and artifact host must mount the same persistent private artifact volume
at the same `ARTIFACT_STORAGE_ROOT` path, outside the public web root. Use
read/write for app and read-only for artifact-host where supported. Separate
anonymous volumes cause saves to succeed and previews to return 404.

The artifact host may reach its restricted database and storage, but must not
initiate traffic to app, workers, processors, metadata services, or the internet.
An ingress proxy spanning both networks is a trusted bridge and must deny
app-hostname proxying from the artifact segment.

## First boot and upgrades

The immutable image runs `config:cache` and the fail-closed production gate
before serving. Supply configuration through the orchestrator/secret manager
before boot. Required settings include:

| Area | Required configuration |
| --- | --- |
| Environment | `APP_ENV=production`, debug off |
| Origins | Distinct HTTPS `APP_URL` and `ARTIFACT_URL` hosts; `ARTIFACT_FRAME_ANCESTORS` equals the app origin |
| Secrets | Strong independent `APP_KEY` and `ARTIFACT_URL_SIGNING_KEY`; dedicated secrets for enabled processors |
| Database | `DB_SSLMODE=verify-full`, `DB_SSLROOTCERT` pointing to a mounted trusted CA |
| Sessions | Secure, encrypted, httpOnly, SameSite lax/strict; no domain covering the artifact host |
| Mail/queue | Deliverable mail transport, `QUEUE_CONNECTION=database`, primary DB connection, queue `after_commit` disabled |
| Proxy/cache | Actual trusted edge addresses and isolated database limiter stores |
| Processors | App-only connection/secret fields and verified containment for each enabled format |

Run the installer as a one-off container after configuring its network, mounts,
and secret files. A container failing the boot gate cannot be used with `exec`.

```sh
# Supply the deployment's mounts and networking, including the admin secret file.
docker run --rm --env-file <your-production-env> <your-image>   php artisan artifactflow:install --env=production --name='Ops' --email='ops@example.test'
docker run --rm --env-file <your-production-env> <your-image>   php artisan artifactflow:doctor
```

Set `ARTIFACTFLOW_ADMIN_PASSWORD_FILE` to a mounted one-shot secret path.
Never pass its value in argv or retain it as `ARTIFACTFLOW_ADMIN_PASSWORD`.
Production installation runs migrations and creates the first System Admin;
it does not generate keys or edit environment files in the image.

For upgrades, apply migrations before serving the new image, update the
artifact-host grants, replace every replica, and run the doctor. In local
Compose the migration command is `make migrate`. Missing schema fails closed
before sessions or MCP authentication. An old running image cannot detect a
release that was downloaded but never started.

Pin deployed images by digest and [verify their attestations](verification.md#verifying-release-images).
Keep PDF/XLSX/DOCX default-off until [processor enablement](processors.md) is complete.

## Cookies, proxies, and TLS

Cookies ignore ports. Use different hostnames and host-only app cookies, or
an app-only `SESSION_DOMAIN`. Every outer proxy must keep artifact responses
free of `Set-Cookie`; Caddy cannot remove headers added downstream.

`TRUSTED_PROXIES` must list the real edge addresses/CIDRs. Empty, wildcard, and
address-space-wide values fail boot. `REMOTE_ADDR` trusts the immediate peer
and is safe only if untrusted clients cannot reach the app port directly;
otherwise they can forge forwarded IPs. The doctor warns on this choice.

PostgreSQL TLS must verify hostname and CA. `require` and `verify-ca` are not
equivalent to `verify-full` and are rejected in production.

HSTS defaults to two years. `includeSubDomains` and `preload` are opt-in and
require every affected hostname to support HTTPS. If enabled, mirror the
application settings in `CADDY_HSTS` for static/error responses.

## Database and limiter isolation

Ordinary `CACHE_STORE` must be shared across replicas. Production rate limiting
supports only `CACHE_LIMITER=database_limiter` and
`ARTIFACT_CACHE_LIMITER=database_artifact_limiter` with distinct tables.
Redis/Memcached/DynamoDB limiter aliases fail closed because configuration
alone cannot prove their credential isolation.

Apply [artifact-host-database-grants.sql](artifact-host-database-grants.sql)
as the database owner after migrations. Use a standalone artifact-host role,
without broader role memberships, through that container's `DB_USERNAME` and
`DB_PASSWORD`. Grant database CONNECT separately.

The role reads presentation, derivative, share/session, and policy state. The
reviewed column-level UPDATE grants support PostgreSQL row locks, not general
writes. It writes only artifact limiter counters and cannot access application
sessions, users, queues, audit, or app limiter state. Keep custom app/artifact
limiter table names distinct. The scheduler prunes expired counters nightly.

## Mail and telemetry

Production rejects `log`/`array` mailers. Configure SMTP or Resend with a verified
sender and run the worker. Invitation state, audit/event rows, and the encrypted
mail job commit in the same database transaction.

At every edge, WAF, load balancer, and APM, redact `/join/*`, reset-link paths,
signed preview queries, cookies, authorization headers, and private content.
Disable session replay on those surfaces. Hashing bearer credentials in the
database cannot protect a plaintext link captured upstream.

## Maintenance

To preserve the cookieless boundary, use only plain `php artisan down` for an artifact-host HTTP role.
The `--secret`, `--redirect`, and `--render` variants bypass route security
middleware; the secret variant attempts a `laravel_maintenance` cookie.
Caddy strips that header, but these variants remain unsupported. Prefer
draining/stopping the role through the orchestrator.

## Optional realtime

Deploy Reverb, set `BROADCAST_CONNECTION=reverb`, configure app ID/key and a
dedicated secret of at least 32 bytes. Keep `REVERB_PUBLIC_URL` and allowed
origins on the app origin, enable rate limiting, and bound connections.
The artifact host receives no Reverb credentials or realtime egress.

For local setup, run the installer with `--reverb`, exit, then `make up` to
reload generated settings. `make reverb-up`/`make reverb-down` offer standalone
control. Reverb shares the app cache for cross-process restart signals.

Run `make verify-reverb-origin` before release. It uses a separate probe
container to test the allowed-origin handshake and foreign-origin rejection,
then cleans up only that probe. A System Admin can enable realtime after its
configuration is complete. Presence is advisory, not a write lock.

The allowed origin receives `101 Switching Protocols` and a connection-established
message. A foreign origin upgrades, then receives Pusher error `4009`.

## Optional Turnstile

Supply `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` only to app-role replicas.
Set `TURNSTILE_EXPECTED_HOSTNAME` to the exact app host. Partial pairs, test
credentials, hostname drift, non-app credentials, and invalid timeouts fail boot.
Connection/request timeouts default to 2/5 seconds, capped at 10/15.

Login and password-recovery forms verify exact hostname and action. Invalid
responses or provider failures fail closed. Existing source/account rate limits
remain active. Non-production malformed configuration returns setup guidance
with 503; production refuses to start.

Enabling it sends browser signals to Cloudflare and the challenge token plus
derived client IP to Siteverify. Review the provider's privacy terms and your
proxy settings. An outage can block login/recovery. Remove both keys and
redeploy to disable it. The [browser verification guide](verification.md)
explains the real-widget test and its network requirement.
