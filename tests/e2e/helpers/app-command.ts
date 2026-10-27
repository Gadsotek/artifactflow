import { execFileSync } from 'node:child_process';
import { constants } from 'node:os';
import { fileURLToPath } from 'node:url';

const repoRoot = fileURLToPath(new URL('../../..', import.meta.url));

interface CommandFailure {
  status?: unknown;
  signal?: unknown;
  code?: unknown;
  stdout?: unknown;
  stderr?: unknown;
}

function diagnostics(error: unknown): string {
  const failure: CommandFailure = error !== null && typeof error === 'object' ? error : {};
  const status =
    typeof failure.status === 'number' &&
    Number.isInteger(failure.status) &&
    failure.status >= 0 &&
    failure.status <= 255
      ? failure.status
      : 'unknown';
  const details = [`make exit=${status}`];
  if (typeof failure.signal === 'string' && Object.hasOwn(constants.signals, failure.signal)) {
    details.push(`signal=${failure.signal}`);
  }
  if (
    typeof failure.code === 'string' &&
    ['ENOENT', 'EACCES', 'EPERM', 'EAGAIN', 'ENOMEM', 'ENOBUFS', 'ETIMEDOUT'].includes(failure.code)
  ) {
    details.push(`spawn=${failure.code}`);
  }

  // Setup commands can contain credentials. Never expose the exception message,
  // command, cause, or raw child output: extract only fixed labels/numeric codes.
  const output = [failure.stdout, failure.stderr]
    .map((value) =>
      Buffer.isBuffer(value) ? value.toString('utf8') : typeof value === 'string' ? value : '',
    )
    .join('\n');
  const childExit = output.match(
    /^make(?:\[\d+\])?: \*\*\* \[(?:[^\]\r\n]+:\d+: )?run-e2e-app-cmd\] Error (\d{1,3})\s*$/mu,
  );
  if (childExit && Number(childExit[1]) <= 255) details.push(`child exit=${childExit[1]}`);
  const sqlState = output.match(/\bSQLSTATE\[([0-9A-Z]{5})\]/u);
  if (sqlState) details.push(`SQLSTATE=${sqlState[1]}`);
  for (const [pattern, label] of [
    [/Allowed memory size of \d+ bytes exhausted/iu, 'PHP memory exhausted'],
    [/Segmentation fault/iu, 'segmentation fault'],
    [/OCI runtime exec failed/iu, 'container exec failed'],
    [/Cannot connect to the Docker daemon/iu, 'Docker daemon unavailable'],
    [/service ["']?e2e-app["']? is not running/iu, 'e2e app is not running'],
    [/Permission denied|Operation not permitted/iu, 'permission denied'],
  ] as const) {
    if (pattern.test(output)) details.push(label);
  }
  return details.join('; ');
}

export function runAppCommand(appCommand: string, failureMessage: string): void {
  const target = process.env.E2E_APP_COMMAND_TARGET ?? 'run-e2e-app-cmd';
  if (!['run-e2e-app-cmd', 'run-app-cmd'].includes(target)) {
    throw new Error('Unsupported e2e app command target.');
  }

  try {
    execFileSync('make', [target, `APP_CMD=${appCommand}`], {
      cwd: repoRoot,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (error) {
    throw new Error(`${failureMessage} (${diagnostics(error)}).`);
  }
}
