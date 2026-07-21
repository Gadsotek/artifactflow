<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Diagnostics\InstallationPlanner;
use App\Application\Diagnostics\InstallationSecret;
use App\Application\Identity\BootstrapSystemAdmin;
use App\Application\Installation\EnvFileWriter;
use App\Application\PageCatalog\SeedDemoContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'artifactflow:install {--env=} {--seed-demo} {--reverb} {--name=} {--email=} {--password=}';

    protected $description = 'Guided first-run install: pick the target environment, provision keys, migrate, and create the system admin.';

    public function handle(
        InstallationPlanner $planner,
        BootstrapSystemAdmin $bootstrapSystemAdmin,
        EnvFileWriter $envWriter,
    ): int {
        $env = $this->resolveTargetEnv();

        if ($env === null) {
            $this->error('Target environment must be one of: local, test, production.');

            return 1;
        }

        $local = $env !== 'production';
        $needsAppKey = InstallationSecret::isMissing($this->configString('app.key'));
        $needsSigningKey = InstallationSecret::isMissing($this->configString('app.artifact_url_signing_key'));
        $wantsReverb = (bool) $this->option('reverb');
        $targetAppEnv = $local ? 'local' : 'production';

        if (!$local && ($needsAppKey || $needsSigningKey || $wantsReverb)) {
            $this->error(
                'Production secrets must be supplied through external environment configuration before running the installer.',
            );

            return 1;
        }

        $plan = $planner->plan($env, $needsAppKey, $needsSigningKey, $wantsReverb);
        $this->info(sprintf('Installing ArtifactFlow (%s mode).', $env));

        // artifactflow:install is deliberately allowed to boot when the ordinary
        // production safety gate cannot, because it is a recovery command. That
        // exemption must not let the installer write through an unsafe database
        // connection or provision an admin under an otherwise invalid deployment.
        // Consume and clear the one-shot password first, then grade every production
        // invariant before migrations or any other database mutation.
        $productionAdminPassword = null;
        if (!$local) {
            $productionAdminPassword = $this->adminPassword();

            if (!$this->productionPreflightPasses($targetAppEnv)) {
                return 1;
            }
        }

        if ($plan->hasStep('app_key')) {
            $this->line('- Generating application key');
            Artisan::call('key:generate', ['--force' => true]);
        }

        if ($plan->hasStep('signing_key')) {
            $this->line('- Generating dedicated artifact signing key');

            if (!$this->runGeneratorScript('scripts/ensure-artifact-signing-key.php')) {
                $this->error('Could not generate the artifact signing key.');

                return 1;
            }
        }

        if ($plan->hasStep('reverb_keys')) {
            $this->line('- Generating Reverb realtime keys');

            if (!$this->runGeneratorScript('scripts/ensure-reverb-keys.php')) {
                $this->error('Could not generate the Reverb realtime keys.');

                return 1;
            }
        }

        $this->line('- Running database migrations');
        $migrationExitCode = Artisan::call('migrate', ['--force' => true]);

        if ($migrationExitCode !== 0) {
            $this->error('Database migrations failed; installation stopped before admin provisioning.');

            return 1;
        }

        $admin = $bootstrapSystemAdmin->handle(
            $this->adminName(),
            $this->adminEmail(),
            $productionAdminPassword ?? $this->adminPassword(),
        );
        $this->info(sprintf('- System admin ready: %s', $admin->email));

        // Local/test source installs own their project .env. Production images are
        // immutable and receive APP_ENV plus every secret from their orchestrator, so
        // production installation must never attempt to write inside the image.
        if ($local) {
            $envWriter->upsert(['APP_ENV' => $targetAppEnv]);
        }

        if ($plan->hasStep('demo') && ($this->option('seed-demo') || $this->confirm('Seed starter demo content?', true))) {
            try {
                app(SeedDemoContent::class)->handle($admin);
                $this->info('- Seeded starter demo content');
            } catch (Throwable $exception) {
                $this->warn(sprintf('- Skipped demo content: %s', $exception->getMessage()));
            }
        }

        if ($plan->hasStep('dev_tools')) {
            $this->newLine();
            $this->line('Local developer tooling:');
            $this->line('  make up-local   # app + Vite + edge + Adminer + Mailpit');
            $this->line('  make adminer-up # database browser');
            $this->line('  make mail-up    # captured outbound mail');
        }

        if ($plan->hasStep('doctor')) {
            $this->newLine();
            $this->line('Running configuration doctor:');
            // Grade the doctor against the environment being installed, not the stale
            // scaffolded APP_ENV this process booted with. Without this a local->production
            // install ran a "local" doctor that skipped every production check and reported
            // a misleading all-clear on a config the next production boot would reject.
            config(['app.env' => $targetAppEnv]);
            $exitCode = Artisan::call('artifactflow:doctor');
            $this->line(trim(Artisan::output()));

            if ($exitCode !== 0) {
                if (!$local) {
                    $this->error('Production installation failed its final configuration doctor.');

                    return 1;
                }

                $this->warn('Resolve the failing doctor checks above before serving traffic.');
            }
        }

        if ($local) {
            $this->newLine();
            $this->line('- Refreshing cached configuration');
            Artisan::call('config:clear');
        }

        $loginUrl = rtrim($this->configString('app.url'), '/') . '/login';
        $this->newLine();
        $this->info(sprintf('Install complete. Sign in at %s', $loginUrl));

        if ($local && ($plan->hasStep('app_key') || $plan->hasStep('signing_key'))) {
            $this->line('If a dev server is already running, restart it so the new keys take effect.');
        }

        return 0;
    }

    private function productionPreflightPasses(string $targetAppEnv): bool
    {
        config(['app.env' => $targetAppEnv]);
        $this->newLine();
        $this->line('Running production configuration preflight:');
        $exitCode = Artisan::call('artifactflow:doctor');
        $this->line(trim(Artisan::output()));

        if ($exitCode === 0) {
            return true;
        }

        $this->error('Production installation aborted before database changes.');

        return false;
    }

    private function resolveTargetEnv(): ?string
    {
        $option = $this->option('env');
        $env = is_string($option) ? strtolower(trim($option)) : '';

        if ($env === '') {
            $choice = $this->choice('Target environment', ['local', 'test', 'production'], 'local');
            $env = is_string($choice) ? $choice : 'local';
        }

        return in_array($env, ['local', 'test', 'production'], true) ? $env : null;
    }

    private function runGeneratorScript(string $relativePath): bool
    {
        return Process::path(base_path())
            ->run([PHP_BINARY, base_path($relativePath)])
            ->successful();
    }

    private function adminName(): string
    {
        $option = $this->option('name');

        return is_string($option) && trim($option) !== '' ? $option : $this->promptFor('System admin name');
    }

    private function adminEmail(): string
    {
        $option = $this->option('email');

        return is_string($option) && trim($option) !== '' ? $option : $this->promptFor('System admin email');
    }

    /**
     * The initial admin password, in order of preference: a secret FILE pointed at by
     * ARTIFACTFLOW_ADMIN_PASSWORD_FILE, then the environment variable
     * ARTIFACTFLOW_ADMIN_PASSWORD, then the legacy --password argument, then an
     * interactive prompt. The file path is the recommended unattended input: unlike an
     * inline `ARTIFACTFLOW_ADMIN_PASSWORD=... artisan install` (which lands in shell
     * history) or --password (visible in `ps`), a mounted secret file leaks the value
     * to neither history nor the process argv.
     *
     * The consumed value also leaves config('app.bootstrap_admin_password') populated
     * (config/app.php maps it from ARTIFACTFLOW_ADMIN_PASSWORD). The production doctor
     * this same process runs would flag that as a persistently configured admin
     * password, so it is cleared here now that it has been consumed -- the doctor then
     * grades the real post-install state rather than the one-shot injection.
     */
    private function adminPassword(): string
    {
        $password = $this->resolveAdminPassword();

        config(['app.bootstrap_admin_password' => null]);

        return $password;
    }

    private function resolveAdminPassword(): string
    {
        $fromFile = $this->secretFromFile(getenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE'));

        if ($fromFile !== '') {
            return $fromFile;
        }

        $fromEnvironment = getenv('ARTIFACTFLOW_ADMIN_PASSWORD');

        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            return $fromEnvironment;
        }

        $option = $this->option('password');

        if (is_string($option) && trim($option) !== '') {
            return $option;
        }

        $secret = $this->secret('System admin password');

        return is_string($secret) ? $secret : '';
    }

    /**
     * Read a one-shot secret from a file path (the Docker/systemd `*_FILE` convention),
     * so the value never appears on the command line or in shell history the way an
     * inline environment assignment or a --password argument does. A single trailing
     * newline is stripped; the remainder is returned verbatim. An unreadable path is a
     * warning, not a hard failure, so the other password sources still apply.
     */
    private function secretFromFile(string|false $path): string
    {
        if (!is_string($path) || trim($path) === '') {
            return '';
        }

        $contents = @file_get_contents(trim($path));

        if ($contents === false) {
            $this->warn(sprintf(
                'Could not read ARTIFACTFLOW_ADMIN_PASSWORD_FILE at %s; trying the other password sources.',
                trim($path),
            ));

            return '';
        }

        return rtrim($contents, "\r\n");
    }

    private function promptFor(string $question): string
    {
        $answer = $this->ask($question);

        return is_string($answer) ? $answer : '';
    }

    private function configString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }
}
