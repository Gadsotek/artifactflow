# Accounts and recovery

[Operations](../OPERATIONS.md)

## First User Setup

Registration is disabled by default. Create a verified login user from the app container. Put the password in a mounted secret file and expose only its path to the command, so the secret never lands in a process listing, shell history, or a long-lived environment variable:

```sh
docker compose exec -T \
  -e ARTIFACTFLOW_CREATE_USER_PASSWORD_FILE=/run/secrets/artifactflow_create_user_password \
  app \
  php artisan artifactflow:create-user \
  --name="Admin User" \
  --email="admin@example.test"
```

> Provision `/run/secrets/artifactflow_create_user_password` through the deployment's secret-file mechanism before running the command, and remove or unmount it afterwards. Avoid `--password="..."` and `-e VAR="value"` with an inline value: both place the secret in the `docker compose exec` argv, where it is visible to other users via `ps`/`/proc` and may be written to shell history.

The password must be at least 12 characters. The command creates a normal verified user, provisions their personal workspace, and records audit/domain events.

Reset a user's password from the app container when an operator recovery path is needed. Use a separate one-shot secret file:

```sh
docker compose exec -T \
  -e ARTIFACTFLOW_RESET_PASSWORD_FILE=/run/secrets/artifactflow_reset_password \
  app \
  php artisan artifactflow:reset-password \
  --email="admin@example.test"
```

The command rotates the user's password and remember token, invalidates database-backed sessions and trusted devices for that user, and records audit/domain events without storing or printing the password. Existing MCP tokens are independent credentials and are deliberately preserved. When the reset responds to suspected credential compromise, revoke them separately:

```sh
docker compose exec -T app php artisan artifactflow:mcp-token-revoke \
  --email="admin@example.test"
```

Create or promote the deployment system admin when needed. Use its dedicated one-shot secret file; the production boot gate rejects the plain `ARTIFACTFLOW_ADMIN_PASSWORD` variable before this command can run:

```sh
docker compose exec -T \
  -e ARTIFACTFLOW_ADMIN_PASSWORD_FILE=/run/secrets/artifactflow_admin_password \
  app \
  php artisan artifactflow:bootstrap-admin \
  --name="Admin User" \
  --email="admin@example.test"
```

Fresh installs require System Admins to enroll TOTP 2FA by default. The password they just used to sign in counts as confirmation for `TWO_FACTOR_ENROLLMENT_PASSWORD_TIMEOUT_SECONDS` (default 180 seconds); the security screen shows the live deadline, and both starting and confirming enrollment must occur inside it. At expiry the browser returns to password confirmation, the pending QR/secret becomes unusable, and restarting enrollment after confirmation generates a fresh one. A System Admin can require 2FA for all users from the installation settings screen. If an operator loses the only admin's second factor, use the console-only break-glass path:

Entering Administration requires a live authenticator code or an unused recovery code; the account password and a trusted-device cookie do not bypass this prompt. A successful proof is cached only for `AUTH_ADMIN_TWO_FACTOR_TIMEOUT` (900 seconds by default). Enabling or disabling two-factor authentication, rotating recovery codes, or revoking all trusted devices advances the account authentication revision and rotates the acting browser session; other sessions fail closed on their next request.

```sh
docker compose exec -T app php artisan artifactflow:disable-2fa \
  --email="admin@example.test" \
  --force \
  --reason="lost device during restore drill" \
  --clear-enforcement
```

`--clear-enforcement` clears the user's per-account 2FA requirement and the install-level System Admin/org-wide requirements so recovery cannot loop back into forced enrollment. The command deletes trusted devices, records audit/domain events with scalar operator context, and must not print TOTP secrets, recovery codes, trusted-device tokens, or token hashes.

After a restore or `APP_KEY` incident, diagnose encrypted TOTP secret readability:

```sh
docker compose exec -T app php artisan artifactflow:diagnose-2fa
```

Use `--json` for automation. The command reports aggregate `checked`, `readable`, and `unreadable` counts only. `APP_KEY` is custody-critical for TOTP secrets and encrypted cookies; rotating or losing it makes TOTP secrets unreadable and causes trusted-device cookies to fail closed to the normal challenge. Recovery codes survive because they are stored only as password hashes.

**Trusted-device tradeoff.** "Remember this device" issues an httpOnly, secure cookie holding a high-entropy token (stored server-side only as a SHA-256 hash) that skips the TOTP challenge for `TWO_FACTOR_TRUSTED_DEVICE_DAYS` (default 30). The cookie is a bearer token: it is not re-bound to the browser or IP on use, so anyone who exfiltrates it can bypass 2FA for that account until it expires or is revoked. This is the standard tradeoff for the feature; operators with stricter requirements should shorten the TTL, and users can revoke trusted devices from their 2FA settings at any time.

Seed the Hello World Markdown and HTML artifact demo pages for an existing user:

```sh
docker compose exec -T app php artisan artifactflow:seed-demo-content \
  --email="admin@example.test"
```
