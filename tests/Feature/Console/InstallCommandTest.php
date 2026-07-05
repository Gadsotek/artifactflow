<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Installation\EnvFileWriter;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The wizard writes APP_ENV (and production boot-gate values) into the
        // deployment .env. Point the writer at a throwaway fixture so no test can
        // ever mutate the project's real .env.
        $this->envPath = storage_path('framework/testing/install-env-' . Str::random(12));
        file_put_contents($this->envPath, "APP_ENV=local\nAPP_URL=https://app.test\n");
        $this->app->instance(EnvFileWriter::class, new EnvFileWriter($this->envPath));
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function test_it_prompts_for_the_target_environment_when_not_supplied(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
        ]);

        $this->runConsoleCommand('artifactflow:install')
            ->expectsChoice('Target environment', 'local', ['local', 'test', 'production'])
            ->expectsQuestion('System admin name', 'Prompted Admin')
            ->expectsQuestion('System admin email', 'prompted-admin@example.test')
            ->expectsQuestion('System admin password', 'correct horse battery staple')
            ->expectsConfirmation('Seed starter demo content?', 'no')
            ->expectsOutputToContain('Installing ArtifactFlow (local mode).')
            ->expectsOutputToContain('- System admin ready: prompted-admin@example.test')
            ->expectsOutputToContain('Install complete. Sign in at')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'prompted-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
        $this->assertSame(0, Page::query()->count());
    }

    public function test_it_rejects_an_unknown_target_environment(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
        ]);

        $this->runConsoleCommand('artifactflow:install', ['--env' => 'staging'])
            ->expectsOutputToContain('Target environment must be one of: local, test, production.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_local_install_generates_signing_key_bootstraps_admin_and_seeds_demo_content(): void
    {
        Storage::fake('artifacts');
        Process::fake();
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => '',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'local',
            '--seed-demo' => true,
            '--name' => 'Install Admin',
            '--email' => 'install-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('Installing ArtifactFlow (local mode).')
            ->expectsOutputToContain('- Generating dedicated artifact signing key')
            ->expectsOutputToContain('- Running database migrations')
            ->expectsOutputToContain('- System admin ready: install-admin@example.test')
            ->expectsOutputToContain('- Seeded starter demo content')
            ->expectsOutputToContain('make up-local   # app + Vite + edge + Adminer + Mailpit')
            ->expectsOutputToContain('- Refreshing cached configuration')
            ->expectsOutputToContain('Install complete. Sign in at')
            ->expectsOutputToContain('restart it so the new keys take effect')
            ->assertExitCode(0);

        Process::assertRan(static function (PendingProcess $process): bool {
            return is_array($process->command)
                && str_contains(implode(' ', $process->command), 'ensure-artifact-signing-key.php');
        });

        $this->assertStringContainsString('APP_ENV=local', (string) file_get_contents($this->envPath));

        $admin = User::query()->where('email', 'install-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
        $this->assertSame(1, Page::query()->where('title', 'Hello World Markdown')->count());
        $this->assertSame(1, Page::query()->where('title', 'Hello World HTML Artifact')->count());
    }

    public function test_local_install_stops_when_the_signing_key_cannot_be_generated(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'generation failed', exitCode: 1),
        ]);
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => '',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'local',
            '--name' => 'Install Admin',
            '--email' => 'install-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('Could not generate the artifact signing key.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->where('email', 'install-admin@example.test')->count());
    }

    public function test_local_install_warns_when_demo_content_seeding_fails(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            'filesystems.disks.artifacts' => ['driver' => 'unsupported-driver'],
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'local',
            '--seed-demo' => true,
            '--name' => 'Install Admin',
            '--email' => 'install-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('- Skipped demo content:')
            ->expectsOutputToContain('Install complete. Sign in at')
            ->assertExitCode(0);

        $this->assertSame(0, Page::query()->count());
    }

    public function test_local_install_generates_reverb_keys_when_requested(): void
    {
        Storage::fake('artifacts');
        Process::fake();
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            'broadcasting.connections.reverb.secret' => '',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'local',
            '--seed-demo' => true,
            '--reverb' => true,
            '--name' => 'Reverb Admin',
            '--email' => 'reverb-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('- Generating Reverb realtime keys')
            ->assertExitCode(0);

        Process::assertRan(static function (PendingProcess $process): bool {
            return is_array($process->command)
                && str_contains(implode(' ', $process->command), 'ensure-reverb-keys.php');
        });
    }

    public function test_test_environment_runs_the_doctor_and_skips_demo_and_dev_tooling(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            // Pin the shipped split-host defaults: the doctor now fails on a
            // shared app/artifact host in every environment, and the ambient
            // container env may carry a developer's own URLs.
            'app.url' => 'http://localhost:18080',
            'app.artifact_url' => 'http://127.0.0.1:18081',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'test',
            '--name' => 'Test Admin',
            '--email' => 'test-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('Installing ArtifactFlow (test mode).')
            ->expectsOutputToContain('Running configuration doctor:')
            ->doesntExpectOutputToContain('Seed starter demo content?')
            ->doesntExpectOutputToContain('make up-local')
            ->expectsOutputToContain('Install complete. Sign in at')
            ->assertExitCode(0);

        // Local semantics: the boot gate stays off, so APP_ENV is written as local.
        $this->assertStringContainsString('APP_ENV=local', (string) file_get_contents($this->envPath));
        $this->assertSame(0, Page::query()->count());
    }

    public function test_production_install_runs_the_doctor_and_warns_on_failing_checks(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'http://localhost:18080',
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            // Pre-supply every boot-gate value so this test exercises only the
            // doctor path with no interactive prompts.
            'database.connections.pgsql.password' => 'already-set',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'mail.default' => 'smtp',
            'trustedproxy.raw' => '10.0.0.1',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'production',
            '--name' => 'Production Admin',
            '--email' => 'production-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('Installing ArtifactFlow (production mode).')
            ->expectsOutputToContain('Running configuration doctor:')
            ->expectsOutputToContain('Resolve the failing doctor checks above before serving traffic.')
            ->expectsOutputToContain('Install complete. Sign in at')
            ->assertExitCode(0);

        $this->assertStringContainsString('APP_ENV=production', (string) file_get_contents($this->envPath));

        $admin = User::query()->where('email', 'production-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
        $this->assertSame(0, Page::query()->count());
    }

    public function test_production_install_persists_the_gate_and_prompts_for_missing_boot_gate_values(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://app.example.test',
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            // The DB password is already present (so the live connection the
            // migration reuses is untouched), but TLS, mail, and proxies are not.
            'database.connections.pgsql.password' => 'already-set',
            'database.connections.pgsql.sslmode' => 'disable',
            'database.connections.pgsql.sslrootcert' => null,
            'mail.default' => 'log',
            'trustedproxy.raw' => '',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'production',
            '--name' => 'Prod Admin',
            '--email' => 'prod-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('Installing ArtifactFlow (production mode).')
            ->expectsOutputToContain('- Configuring production boot-gate values')
            ->expectsQuestion('Path to the database TLS root certificate (DB_SSLROOTCERT)', '/etc/ssl/certs/pg-root.crt')
            ->expectsQuestion('Mail transport (MAIL_MAILER, e.g. smtp/ses/postmark)', 'smtp')
            ->expectsQuestion('Trusted proxy addresses/CIDRs for the TLS edge (TRUSTED_PROXIES)', '10.0.0.0/24')
            ->expectsOutputToContain('Install complete. Sign in at')
            ->assertExitCode(0);

        $env = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('DB_SSLMODE=verify-full', $env);
        $this->assertStringContainsString('DB_SSLROOTCERT=/etc/ssl/certs/pg-root.crt', $env);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $env);
        $this->assertStringContainsString('TRUSTED_PROXIES=10.0.0.0/24', $env);

        $admin = User::query()->where('email', 'prod-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
    }

    public function test_production_install_does_not_arm_the_environment_when_provisioning_fails(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'generation failed', exitCode: 1),
        ]);
        config([
            'app.env' => 'production',
            'app.url' => 'https://app.example.test',
            'app.key' => $this->strongSecret('a'),
            // Missing so the wizard tries (and, faked, fails) to generate it before it
            // reaches migrations or the admin -- i.e. provisioning does not complete.
            'app.artifact_url_signing_key' => '',
            'database.connections.pgsql.password' => 'already-set',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'mail.default' => 'smtp',
            'trustedproxy.raw' => '10.0.0.1',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'production',
            '--name' => 'Prod Admin',
            '--email' => 'prod-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('Could not generate the artifact signing key.')
            ->assertExitCode(1);

        // A failed install must not declare the deployment production: the fixture .env
        // still reads APP_ENV=local, so the next boot -- and a re-run of the installer --
        // is not locked out by the production boot gate.
        $env = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('APP_ENV=local', $env);
        $this->assertStringNotContainsString('APP_ENV=production', $env);
        $this->assertSame(0, User::query()->where('email', 'prod-admin@example.test')->count());
    }

    public function test_production_install_grades_the_final_doctor_against_the_target_environment(): void
    {
        Storage::fake('artifacts');
        // config('app.env') stays at the harness default (testing), never production, so
        // this proves the wizard grades the doctor against the install TARGET rather than
        // the environment this process happens to have booted with.
        config([
            'app.url' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            'database.connections.pgsql.password' => 'already-set',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'mail.default' => 'smtp',
            'trustedproxy.raw' => '10.0.0.1',
        ]);

        $this->assertNotSame('production', config('app.env'));

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'production',
            '--name' => 'Prod Doctor Admin',
            '--email' => 'prod-doctor-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('ArtifactFlow doctor (production mode)')
            ->assertExitCode(0);
    }

    public function test_admin_password_is_read_from_the_environment_without_an_argument_or_prompt(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
        ]);

        // The recommended unattended path: the secret arrives through the environment, so
        // no --password argument (visible in `ps`/shell history) is passed, and the command
        // must not fall back to the interactive prompt -- the test provides neither.
        putenv('ARTIFACTFLOW_ADMIN_PASSWORD=correct horse battery staple');

        try {
            $this->runConsoleCommand('artifactflow:install', [
                '--env' => 'test',
                '--name' => 'Env Admin',
                '--email' => 'env-admin@example.test',
            ])
                ->expectsOutputToContain('- System admin ready: env-admin@example.test')
                ->expectsOutputToContain('Install complete. Sign in at')
                ->assertExitCode(0);
        } finally {
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD');
        }

        $admin = User::query()->where('email', 'env-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
        // The environment value is the credential actually stored, proving it was consumed.
        $this->assertTrue(Hash::check('correct horse battery staple', (string) $admin->password));
    }

    public function test_environment_admin_password_takes_precedence_over_the_argument(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
        ]);

        // When both are present the safer environment secret wins, so a script still carrying
        // a --password argument is nudged onto the credential that did not leak.
        putenv('ARTIFACTFLOW_ADMIN_PASSWORD=environment-password-value');

        try {
            $this->runConsoleCommand('artifactflow:install', [
                '--env' => 'test',
                '--name' => 'Precedence Admin',
                '--email' => 'precedence-admin@example.test',
                '--password' => 'argument-password-value',
            ])
                ->expectsOutputToContain('- System admin ready: precedence-admin@example.test')
                ->assertExitCode(0);
        } finally {
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD');
        }

        $admin = User::query()->where('email', 'precedence-admin@example.test')->sole();
        $this->assertTrue(Hash::check('environment-password-value', (string) $admin->password));
        $this->assertFalse(Hash::check('argument-password-value', (string) $admin->password));
    }

    public function test_admin_password_is_read_from_a_secret_file_without_env_argument_or_prompt(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
        ]);

        // The leak-free unattended path: the secret is mounted as a file (Docker/systemd
        // *_FILE convention), so it appears in neither shell history (an inline
        // ARTIFACTFLOW_ADMIN_PASSWORD=... assignment would) nor the process argv
        // (--password would). No env var, argument, or prompt is provided here.
        $secretFile = storage_path('framework/testing/admin-pw-' . Str::random(12));
        file_put_contents($secretFile, "correct horse battery staple\n");
        putenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE=' . $secretFile);

        try {
            $this->runConsoleCommand('artifactflow:install', [
                '--env' => 'test',
                '--name' => 'File Admin',
                '--email' => 'file-admin@example.test',
            ])
                ->expectsOutputToContain('- System admin ready: file-admin@example.test')
                ->expectsOutputToContain('Install complete. Sign in at')
                ->assertExitCode(0);
        } finally {
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE');
            @unlink($secretFile);
        }

        $admin = User::query()->where('email', 'file-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
        // The trailing newline is stripped; the file's contents are the stored credential.
        $this->assertTrue(Hash::check('correct horse battery staple', (string) $admin->password));
    }

    public function test_a_secret_file_takes_precedence_over_the_environment_and_argument(): void
    {
        config([
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
        ]);

        $secretFile = storage_path('framework/testing/admin-pw-' . Str::random(12));
        file_put_contents($secretFile, 'file-password-value');
        putenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE=' . $secretFile);
        putenv('ARTIFACTFLOW_ADMIN_PASSWORD=environment-password-value');

        try {
            $this->runConsoleCommand('artifactflow:install', [
                '--env' => 'test',
                '--name' => 'File Precedence Admin',
                '--email' => 'file-precedence-admin@example.test',
                '--password' => 'argument-password-value',
            ])
                ->expectsOutputToContain('- System admin ready: file-precedence-admin@example.test')
                ->assertExitCode(0);
        } finally {
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE');
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD');
            @unlink($secretFile);
        }

        $admin = User::query()->where('email', 'file-precedence-admin@example.test')->sole();
        $this->assertTrue(Hash::check('file-password-value', (string) $admin->password));
        $this->assertFalse(Hash::check('environment-password-value', (string) $admin->password));
        $this->assertFalse(Hash::check('argument-password-value', (string) $admin->password));
    }

    public function test_the_consumed_one_shot_admin_password_does_not_linger_for_the_in_process_doctor(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            'database.connections.pgsql.password' => 'already-set',
            'database.connections.pgsql.sslmode' => 'verify-full',
            'database.connections.pgsql.sslrootcert' => '/etc/ssl/certs/pg-root.crt',
            'mail.default' => 'smtp',
            'trustedproxy.raw' => '10.0.0.1',
            // config/app.php maps this from ARTIFACTFLOW_ADMIN_PASSWORD; the one-shot
            // secret injected for this install therefore arrives configured. The final
            // doctor runs in this same process and would flag it as persistently
            // configured unless the wizard clears it after consuming it.
            'app.bootstrap_admin_password' => 'correct horse battery staple',
        ]);

        putenv('ARTIFACTFLOW_ADMIN_PASSWORD=correct horse battery staple');

        try {
            $this->runConsoleCommand('artifactflow:install', [
                '--env' => 'production',
                '--name' => 'OneShot Admin',
                '--email' => 'oneshot-admin@example.test',
            ])
                ->expectsOutputToContain('No persistent bootstrap passwords are configured.')
                ->doesntExpectOutputToContain('Remove ARTIFACTFLOW_ADMIN_PASSWORD and related fallbacks')
                ->assertExitCode(0);
        } finally {
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD');
        }

        // The consumed one-shot password no longer lingers in the live config.
        $this->assertNull(config('app.bootstrap_admin_password'));
    }

    public function test_production_install_applies_collected_boot_gate_values_so_the_final_doctor_grades_them_fresh(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://app.example.test',
            'app.artifact_url' => 'https://artifacts.example.test',
            'app.key' => $this->strongSecret('a'),
            'app.artifact_url_signing_key' => $this->strongSecret('b'),
            // The DB password is present (so the live connection the migration reuses is
            // untouched), but TLS, mail, and proxies are unsafe/missing. The wizard
            // collects them; the final doctor must grade the just-collected values, not
            // the stale scaffolded ones.
            'database.connections.pgsql.password' => 'already-set',
            'database.connections.pgsql.sslmode' => 'disable',
            'database.connections.pgsql.sslrootcert' => null,
            'mail.default' => 'log',
            'trustedproxy.raw' => '',
        ]);

        $this->runConsoleCommand('artifactflow:install', [
            '--env' => 'production',
            '--name' => 'Fresh Doctor Admin',
            '--email' => 'fresh-doctor-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('- Configuring production boot-gate values')
            ->expectsQuestion('Path to the database TLS root certificate (DB_SSLROOTCERT)', '/etc/ssl/certs/pg-root.crt')
            ->expectsQuestion('Mail transport (MAIL_MAILER, e.g. smtp/ses/postmark)', 'smtp')
            ->expectsQuestion('Trusted proxy addresses/CIDRs for the TLS edge (TRUSTED_PROXIES)', '10.0.0.0/24')
            // The freshly collected values reach the doctor: none of the stale-value
            // failure messages appear in its report.
            ->doesntExpectOutputToContain('will not deliver in production')
            ->doesntExpectOutputToContain('Set DB_SSLMODE=verify-full and DB_SSLROOTCERT')
            ->doesntExpectOutputToContain('Set TRUSTED_PROXIES to the real edge')
            ->assertExitCode(0);

        // The live config the final doctor read carries the operator's answers.
        $this->assertSame('verify-full', config('database.connections.pgsql.sslmode'));
        $this->assertSame('/etc/ssl/certs/pg-root.crt', config('database.connections.pgsql.sslrootcert'));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('10.0.0.0/24', config('trustedproxy.raw'));
    }

    private function strongSecret(string $byte): string
    {
        return 'base64:' . base64_encode(str_repeat($byte, 32));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runConsoleCommand(string $command, array $parameters = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }
}
