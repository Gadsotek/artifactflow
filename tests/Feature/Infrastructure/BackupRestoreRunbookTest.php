<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BackupRestoreRunbookTest extends TestCase
{
    public function test_makefile_exposes_backup_restore_and_verify_targets(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        $this->assertStringContainsString('backup:', $makefile);
        $this->assertStringContainsString('restore:', $makefile);
        $this->assertStringContainsString('backup-verify:', $makefile);
        $this->assertStringContainsString("php artisan artifactflow:verify-artifacts --sample=25", $makefile);
    }

    public function test_backup_script_enforces_database_first_then_artifact_snapshot_ordering(): void
    {
        $script = $this->readProjectFile('scripts/backup.sh');
        $postgresDumpOffset = strpos($script, 'pg_dump -U');
        $artifactTarOffset = strpos($script, 'tar -C');

        $this->assertIsInt($postgresDumpOffset);
        $this->assertIsInt($artifactTarOffset);
        $this->assertLessThan($artifactTarOffset, $postgresDumpOffset);
        $this->assertStringContainsString('manifest.json', $script);
        $this->assertStringContainsString('--dry-run', $script);
        $this->assertStringNotContainsString('rm -rf', $script);
        $this->assertStringNotContainsString('rm -r', $script);
    }

    public function test_backup_script_creates_private_directory_and_files(): void
    {
        $root = sys_get_temp_dir() . '/artifactflow-backup-perms-' . bin2hex(random_bytes(8));
        $binDir = $root . '/bin';
        $backupDir = $root . '/backups';
        $compose = $binDir . '/compose';

        mkdir($binDir, 0700, true);
        file_put_contents($compose, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "version" ]]; then
    printf 'fake-compose\n'
    exit 0
fi

command="${*: -1}"

case "$command" in
    *"pg_dump -U"*)
        printf 'PGDUMP'
        ;;
    *"tar -C"*)
        printf 'ARTIFACTS'
        ;;
    *"select count(*) from page_versions"*)
        printf '0'
        ;;
    *"find "*)
        printf '0'
        ;;
    *"pg_dump --version"*)
        printf 'pg_dump fake'
        ;;
    *"tar --version"*)
        printf 'tar fake'
        ;;
    *)
        printf 'unexpected fake compose command: %s\n' "$command" >&2
        exit 9
        ;;
esac
SH);
        chmod($compose, 0700);

        $process = new Process(
            ['bash', 'scripts/backup.sh'],
            base_path(),
            [
                'APP_DB_PASS' => 'test-password',
                'APP_DB_USER' => 'artifactflow',
                'APP_SERVICE' => 'app',
                'BACKUP_DIR' => $backupDir,
                'COMPOSE' => $compose,
                'POSTGRES_DB' => 'artifactflow',
            ],
            null,
            15,
        );

        try {
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(0, $process->getExitCode(), $output);

            $backupEntries = glob($backupDir . '/*', GLOB_ONLYDIR);
            $this->assertIsArray($backupEntries);
            $this->assertCount(1, $backupEntries);

            $targetDir = $backupEntries[0];
            $this->assertFileMode($targetDir, 0700);

            foreach (['postgres.dump', 'artifacts.tar.gz', 'manifest.json'] as $file) {
                $path = $targetDir . '/' . $file;
                $this->assertFileExists($path);
                $this->assertFileMode($path, 0600);
            }

            $manifestContents = file_get_contents($targetDir . '/manifest.json');
            $this->assertIsString($manifestContents);
            $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($manifest);
            $this->assertSame(1, $manifest['format_version'] ?? null);
            $this->assertSame(hash_file('sha256', $targetDir . '/postgres.dump'), $manifest['postgres_sha256'] ?? null);
            $this->assertSame(hash_file('sha256', $targetDir . '/artifacts.tar.gz'), $manifest['artifacts_sha256'] ?? null);
        } finally {
            $this->cleanupBackupFixture($root);
        }
    }

    public function test_restore_rejects_a_mismatched_backup_pair_before_contacting_compose(): void
    {
        $root = sys_get_temp_dir() . '/artifactflow-restore-hash-' . bin2hex(random_bytes(8));
        $binDir = $root . '/bin';
        $backupDir = $root . '/backup';
        $compose = $binDir . '/compose';
        $composeMarker = $root . '/compose-called';

        mkdir($binDir, 0700, true);
        mkdir($backupDir, 0700, true);
        file_put_contents($backupDir . '/postgres.dump', 'database-a');
        file_put_contents($backupDir . '/artifacts.tar.gz', 'artifacts-a');
        file_put_contents($backupDir . '/manifest.json', json_encode([
            'format_version' => 1,
            'postgres_sha256' => hash('sha256', 'database-a'),
            'artifacts_sha256' => hash('sha256', 'artifacts-a'),
        ], JSON_THROW_ON_ERROR));
        file_put_contents($backupDir . '/postgres.dump', 'database-b');
        file_put_contents($compose, "#!/usr/bin/env bash\ntouch '" . $composeMarker . "'\nexit 91\n");
        chmod($compose, 0700);

        $process = new Process(
            ['bash', 'scripts/restore.sh', $backupDir],
            base_path(),
            ['COMPOSE' => $compose],
            null,
            15,
        );

        try {
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('PostgreSQL dump hash does not match', $output);
            $this->assertFileDoesNotExist($composeMarker);
        } finally {
            foreach (['postgres.dump', 'artifacts.tar.gz', 'manifest.json'] as $file) {
                unlink($backupDir . '/' . $file);
            }

            unlink($compose);
            rmdir($backupDir);
            rmdir($binDir);
            rmdir($root);
        }
    }

    public function test_restore_explicitly_upgrades_a_recognizable_legacy_manifest_before_use(): void
    {
        $root = sys_get_temp_dir() . '/artifactflow-restore-legacy-' . bin2hex(random_bytes(8));
        $binDir = $root . '/bin';
        $backupDir = $root . '/backup';
        $compose = $binDir . '/compose';

        mkdir($binDir, 0700, true);
        mkdir($backupDir, 0700, true);
        file_put_contents($backupDir . '/postgres.dump', 'database');
        file_put_contents($backupDir . '/artifacts.tar.gz', 'legacy-artifacts');
        file_put_contents($backupDir . '/manifest.json', <<<'JSON'
{
  "created_at": "20260720T120000Z",
  "ordering": "postgres_dump_first_artifacts_snapshot_second",
  "postgres_dump": "postgres.dump",
  "artifacts_archive": "artifacts.tar.gz",
  "page_versions_count": 7,
  "artifact_file_count": 5,
  "postgres_version": "pg_dump (PostgreSQL) 17.5",
  "tar_version": "tar (GNU tar) 1.35",
  "compose_version": "2.39.1"
}
JSON);
        file_put_contents($compose, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$*" == "ps --services --status running" ]]; then
    printf 'app\ndb\n'
    exit 0
fi

if [[ "$*" == "ps --services --status paused" || "$*" == "ps --services --status restarting" ]]; then
    exit 0
fi

printf 'unexpected fake compose command: %s\n' "$*" >&2
exit 92
SH);
        chmod($compose, 0700);

        $refusedProcess = new Process(
            ['bash', 'scripts/restore.sh', $backupDir],
            base_path(),
            ['COMPOSE' => $compose],
            null,
            15,
        );
        $process = new Process(
            ['bash', 'scripts/restore.sh', '--upgrade-legacy-manifest', $backupDir],
            base_path(),
            ['COMPOSE' => $compose],
            null,
            15,
        );

        try {
            $refusedProcess->run();
            $refusedOutput = $refusedProcess->getOutput() . $refusedProcess->getErrorOutput();
            $this->assertSame(1, $refusedProcess->getExitCode(), $refusedOutput);
            $this->assertStringContainsString('missing a supported format version or SHA-256', $refusedOutput);
            $legacyManifestContents = file_get_contents($backupDir . '/manifest.json');
            $this->assertIsString($legacyManifestContents);
            $this->assertStringNotContainsString('format_version', $legacyManifestContents);

            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('Upgrading legacy unhashed backup manifest', $output);
            $this->assertStringContainsString('Legacy backup manifest upgraded with SHA-256 payload hashes', $output);

            $manifestContents = file_get_contents($backupDir . '/manifest.json');
            $this->assertIsString($manifestContents);
            $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($manifest);
            $this->assertSame(1, $manifest['format_version'] ?? null);
            $this->assertSame(hash('sha256', 'database'), $manifest['postgres_sha256'] ?? null);
            $this->assertSame(hash('sha256', 'legacy-artifacts'), $manifest['artifacts_sha256'] ?? null);
        } finally {
            foreach (['postgres.dump', 'artifacts.tar.gz', 'manifest.json'] as $file) {
                unlink($backupDir . '/' . $file);
            }

            unlink($compose);
            rmdir($backupDir);
            rmdir($binDir);
            rmdir($root);
        }
    }

    public function test_restore_refuses_to_upgrade_an_unrecognized_legacy_manifest(): void
    {
        $root = sys_get_temp_dir() . '/artifactflow-restore-legacy-invalid-' . bin2hex(random_bytes(8));
        $binDir = $root . '/bin';
        $backupDir = $root . '/backup';
        $compose = $binDir . '/compose';
        $composeMarker = $root . '/compose-called';

        mkdir($binDir, 0700, true);
        mkdir($backupDir, 0700, true);
        file_put_contents($backupDir . '/postgres.dump', 'database');
        file_put_contents($backupDir . '/artifacts.tar.gz', 'artifacts');
        file_put_contents($backupDir . '/manifest.json', <<<'JSON'
{
  "created_at": "20260720T120000Z",
  "ordering": "postgres_dump_first_artifacts_snapshot_second",
  "postgres_dump": "postgres.dump",
  "artifacts_archive": "artifacts.tar.gz",
  "page_versions_count": 7,
  "artifact_file_count": 5,
  "postgres_version": "pg_dump (PostgreSQL) 17.5",
  "tar_version": "tar (GNU tar) 1.35",
  "compose_version": "2.39.1",
  "unexpected_field": "must-not-be-trusted"
}
JSON);
        file_put_contents($compose, "#!/usr/bin/env bash\ntouch '" . $composeMarker . "'\nexit 91\n");
        chmod($compose, 0700);

        $process = new Process(
            ['bash', 'scripts/restore.sh', '--upgrade-legacy-manifest', $backupDir],
            base_path(),
            ['COMPOSE' => $compose],
            null,
            15,
        );

        try {
            $process->run();
            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertSame(1, $process->getExitCode(), $output);
            $this->assertStringContainsString('not a recognized legacy ArtifactFlow backup manifest', $output);
            $this->assertFileDoesNotExist($composeMarker);
            $manifestContents = file_get_contents($backupDir . '/manifest.json');
            $this->assertIsString($manifestContents);
            $this->assertStringNotContainsString('format_version', $manifestContents);
        } finally {
            foreach (['postgres.dump', 'artifacts.tar.gz', 'manifest.json'] as $file) {
                unlink($backupDir . '/' . $file);
            }

            unlink($compose);
            if (is_file($composeMarker)) {
                unlink($composeMarker);
            }

            rmdir($backupDir);
            rmdir($binDir);
            rmdir($root);
        }
    }

    public function test_restore_refuses_to_run_while_an_application_role_is_running_paused_or_restarting(): void
    {
        foreach (['app', 'artifact-host', 'worker', 'scheduler'] as $activeRole) {
            foreach (['running', 'paused', 'restarting'] as $unsafeState) {
                $root = sys_get_temp_dir() . '/artifactflow-restore-quiescence-' . $activeRole . '-' . $unsafeState . '-' . bin2hex(random_bytes(8));
                $binDir = $root . '/bin';
                $backupDir = $root . '/backup';
                $compose = $binDir . '/compose';

                mkdir($binDir, 0700, true);
                mkdir($backupDir, 0700, true);
                file_put_contents($backupDir . '/postgres.dump', 'database');
                file_put_contents($backupDir . '/artifacts.tar.gz', 'not-yet-inspected');
                file_put_contents($backupDir . '/manifest.json', json_encode([
                    'format_version' => 1,
                    'postgres_sha256' => hash('sha256', 'database'),
                    'artifacts_sha256' => hash('sha256', 'not-yet-inspected'),
                ], JSON_THROW_ON_ERROR));
                file_put_contents($compose, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$*" == "ps --services --status ${ACTIVE_STATE:?}" ]]; then
    printf '%s\ndb\n' "${ACTIVE_ROLE:?}"
    exit 0
fi

if [[ "$*" == "ps --services --status running" || "$*" == "ps --services --status paused" || "$*" == "ps --services --status restarting" ]]; then
    exit 0
fi

printf 'unexpected fake compose command: %s\n' "$*" >&2
exit 92
SH);
                chmod($compose, 0700);

                $process = new Process(
                    ['bash', 'scripts/restore.sh', $backupDir],
                    base_path(),
                    [
                        'ACTIVE_ROLE' => $activeRole,
                        'ACTIVE_STATE' => $unsafeState,
                        'COMPOSE' => $compose,
                    ],
                    null,
                    15,
                );

                try {
                    $process->run();
                    $output = $process->getOutput() . $process->getErrorOutput();

                    $this->assertSame(1, $process->getExitCode(), $output);
                    $this->assertStringContainsString(
                        'Refusing restore while application roles are running, paused, or restarting: ' . $activeRole,
                        $output,
                    );
                } finally {
                    foreach (['postgres.dump', 'artifacts.tar.gz', 'manifest.json'] as $file) {
                        unlink($backupDir . '/' . $file);
                    }

                    unlink($compose);
                    rmdir($backupDir);
                    rmdir($binDir);
                    rmdir($root);
                }
            }
        }
    }

    public function test_restore_script_requires_force_confirmation_and_avoids_recursive_deletion(): void
    {
        $script = $this->readProjectFile('scripts/restore.sh');

        $this->assertStringContainsString('--force', $script);
        $this->assertStringContainsString('--dry-run', $script);
        $this->assertStringContainsString('RESTORE', $script);
        $this->assertStringContainsString('pg_restore --clean --if-exists', $script);
        $this->assertStringNotContainsString('rm -rf', $script);
        $this->assertStringNotContainsString('rm -r', $script);
    }

    public function test_restore_script_validates_archive_members_and_hardens_tar_extraction(): void
    {
        $script = $this->readProjectFile('scripts/restore.sh');

        $this->assertStringContainsString('validate_artifacts_archive', $script);
        $this->assertStringContainsString('tar -tzf "$archive"', $script);
        $this->assertStringContainsString('(^|\\/)\\.\\.($|\\/)', $script);
        $this->assertStringContainsString('tar -tvzf "$archive"', $script);
        $this->assertStringContainsString('--no-same-owner', $script);
        $this->assertStringContainsString('--no-same-permissions', $script);
        $this->assertStringContainsString('find "$artifact_root" -mindepth 1 -type l', $script);
    }

    public function test_artifact_verification_all_mode_is_chunked_for_large_installations(): void
    {
        $service = $this->readProjectFile('app/Application/PageCatalog/VerifyArtifactIntegrity.php');

        $this->assertStringContainsString('ALL_BATCH_SIZE', $service);
        $this->assertStringContainsString('chunkById(', $service);
        $this->assertStringContainsString("'uid'", $service);
    }

    public function test_operations_runbook_covers_backup_restore_keys_retention_and_verification(): void
    {
        $operations = $this->readProjectFile('docs/OPERATIONS.md');

        $this->assertStringContainsString('## Backup & Restore', $operations);
        $this->assertStringContainsString('PostgreSQL', $operations);
        $this->assertStringContainsString('private artifacts disk', $operations);
        $this->assertStringContainsString('database dump first', $operations);
        $this->assertStringContainsString('APP_KEY', $operations);
        $this->assertStringContainsString('ARTIFACT_URL_SIGNING_KEY', $operations);
        $this->assertStringContainsString('same persistent private artifact', $operations);
        $this->assertStringContainsString('ARTIFACT_STORAGE_ROOT', $operations);
        $this->assertStringContainsString('--upgrade-legacy-manifest', $operations);
        $this->assertStringContainsString('running, paused, or restarting', $operations);
        $this->assertStringContainsString('Retention', $operations);
        $this->assertStringContainsString('make backup-verify', $operations);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function assertFileMode(string $path, int $expectedMode): void
    {
        $mode = fileperms($path);

        $this->assertIsInt($mode);
        $this->assertSame(
            sprintf('%04o', $expectedMode),
            sprintf('%04o', $mode & 0777),
            sprintf('Unexpected permissions for %s.', $path),
        );
    }

    private function cleanupBackupFixture(string $root): void
    {
        $backupEntries = glob($root . '/backups/*', GLOB_ONLYDIR);
        if (is_array($backupEntries)) {
            foreach ($backupEntries as $backupDir) {
                foreach (['postgres.dump', 'artifacts.tar.gz', 'manifest.json'] as $file) {
                    $path = $backupDir . '/' . $file;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }

                if (is_dir($backupDir)) {
                    rmdir($backupDir);
                }
            }
        }

        if (is_file($root . '/bin/compose')) {
            unlink($root . '/bin/compose');
        }

        foreach ([$root . '/backups', $root . '/bin', $root] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
