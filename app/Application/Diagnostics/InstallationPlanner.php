<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

/**
 * Decides the ordered first-run steps for artifactflow:install. The plan is aware
 * of the operator-chosen target environment (local / test / production). Local and
 * test environments generate app-internal secrets they are missing, then diverge:
 * `local` adds developer
 * conveniences (demo content, dev-tooling hints); `test` is local semantics on a
 * non-dev box, so it skips demo data and ends on the doctor punch list; and
 * `production` skips all filesystem-writing generators and ends on the doctor;
 * immutable deployments supply their environment and secrets before this plan runs.
 */
final readonly class InstallationPlanner
{
    public function plan(string $env, bool $needsAppKey, bool $needsSigningKey, bool $wantsReverb = false): InstallationPlan
    {
        $local = $env !== 'production';
        $steps = [];

        if ($local && $needsAppKey) {
            $steps[] = new InstallationStep('app_key', 'Generate the application key');
        }

        if ($local && $needsSigningKey) {
            $steps[] = new InstallationStep('signing_key', 'Generate the dedicated artifact signing key');
        }

        if ($local && $wantsReverb) {
            $steps[] = new InstallationStep('reverb_keys', 'Generate the Reverb realtime keys');
        }

        $steps[] = new InstallationStep('migrate', 'Run database migrations');
        $steps[] = new InstallationStep('admin', 'Create or promote the system admin');

        if ($env === 'local') {
            $steps[] = new InstallationStep('demo', 'Seed starter demo content');
            $steps[] = new InstallationStep('dev_tools', 'Show local developer tooling hints');
        } else {
            $steps[] = new InstallationStep('doctor', 'Run the read-only configuration doctor');
        }

        $steps[] = new InstallationStep('login_url', 'Print the sign-in URL');

        return new InstallationPlan($local, $steps);
    }
}
