# Local setup

[Operations](../OPERATIONS.md)

## Local Runtime

The local stack follows the architecture document:

| Service | Role |
| --- | --- |
| `app` | Main Laravel HTTP origin. |
| `artifact-host` | Same code image, separate stateless artifact-serving origin. |
| `image-parser` | Minimal networkless PNG/JPEG decoder and normalizer reached through a Unix socket; no app source, database, artifact storage, or public port. |
| `pdf-processor` | Default-running, networkless PDF validator/text extractor reached through a Unix socket; PDF application behavior remains default-off. |
| `xlsx-processor` | Default-running, networkless XLSX validator and typed-manifest projector reached through a Unix socket; XLSX application behavior remains default-off. |
| `docx-processor` | Default-running, networkless DOCX validator and LibreOffice-to-PDF converter reached through a Unix socket; DOCX application behavior remains default-off and also requires the PDF processor. |
| `worker` | Queue worker (`queue:work`). Scans, projections, and audit side effects run synchronously inside the write transaction; the only queued work today is outbound mail. |
| `scheduler` | Laravel scheduler loop (`schedule:work`): outbox dispatch and the nightly retention jobs. |
| `reverb` | Local WebSocket runtime; realtime application behavior remains disabled until configured and enabled. |
| `db` | PostgreSQL 17 for app data, queues, search, and event outbox. |
| `edge` | Optional local Caddy reverse proxy for named host routing. |

Start the core stack:

```sh
make up
```

This idempotently provisions the local `ARTIFACT_URL_SIGNING_KEY`. Compose uses
explicit development-only processor-secret fallbacks until the guided installer
generates distinct local values. All processors run without public ports, while
the local/test installer keeps PDF, XLSX, and DOCX application behavior disabled
by default. Accept the corresponding prompts, or pass `--pdf`, `--xlsx`, or
`--docx` for an unattended install. The interactive wizard asks about Word
first. Choosing DOCX enables PDF because every Word preview must pass the
separate PDF processor, and the redundant standalone-PDF question is skipped.
When DOCX is declined, the wizard asks about standalone PDF next and XLSX last.
The installer runs the doctor in every environment. After a document-setting
change, exit the app container, rerun `make up` so Compose reloads the flags and generated secrets,
then run `make doctor`. Treat XLSX as unavailable until its signed live check
passes, and DOCX as unavailable until both its converter and downstream PDF
checks pass.
Production installation remains default-off; production enablement is an
explicit deployment operation governed by each format's checklist below.

Start the full local stack with Vite, Caddy edge routing, Adminer, and Mailpit:

```sh
make up-local
```

Default direct ports:

| Endpoint | URL |
| --- | --- |
| Main app | `http://localhost:18080` |
| Artifact host | `http://127.0.0.1:18081` |
| Mailpit | `http://localhost:18033` |
| Adminer | `http://localhost:18089` |

The artifact host intentionally uses `127.0.0.1` while the app uses `localhost`. Cookies ignore the port (RFC 6265), so two origins on the same host that differ only by port would send the app session cookie along with every artifact request; a different host is what keeps app cookies off the artifact origin. Keep the two hosts different if you customise these URLs (`php artisan artifactflow:doctor` fails when they collide).

The repository `docker-compose.yml` is a local development stack. It intentionally uses loopback ports, local-only credentials, `APP_DEBUG=true`, and non-TLS PostgreSQL (`DB_SSLMODE=disable`). Do not use it as a production compose template.

Optional local hostnames through the Caddy edge (`make up-local`):

```text
127.0.0.1 app.artifactflow.test
127.0.0.1 artifacts.artifactflow.test
```

Then open:

```text
http://app.artifactflow.test:18085
http://artifacts.artifactflow.test:18085
```
