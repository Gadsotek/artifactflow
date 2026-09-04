<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

/**
 * Decides the ordered first-run steps for artifactflow:install. The plan is aware
 * of the operator-chosen target environment (local / test / production). Local and
 * test environments generate requested app-internal secrets that are missing,
 * published, weak, or reused across boundaries, then
 * diverge: `local` adds developer conveniences (demo content and dev-tooling
 * hints); `test` is local semantics on a non-dev box, so it skips those extras;
 * every environment ends on the doctor punch list; and production skips all
 * filesystem-writing generators;
 * immutable deployments supply their environment and secrets before this plan runs.
 */
final readonly class InstallationPlanner
{
    public function plan(
        string $env,
        bool $needsAppKey,
        bool $needsSigningKey,
        bool $needsImageParserSecret,
        bool $wantsReverb = false,
        bool $wantsPdf = false,
        bool $needsPdfProcessorSecret = false,
        bool $wantsXlsx = false,
        bool $needsXlsxProcessorSecret = false,
        bool $wantsDocx = false,
        bool $needsDocxProcessorSecret = false,
    ): InstallationPlan {
        $local = $env !== 'production';
        $steps = [];

        if ($local && $needsAppKey) {
            $steps[] = new InstallationStep('app_key', 'Generate the application key');
        }

        if ($local && $needsSigningKey) {
            $steps[] = new InstallationStep('signing_key', 'Generate the dedicated artifact signing key');
        }

        if ($local && $needsImageParserSecret) {
            $steps[] = new InstallationStep('image_parser_secret', 'Generate the image parser shared secret');
        }

        if ($local && $wantsReverb) {
            $steps[] = new InstallationStep('reverb_keys', 'Generate the Reverb realtime keys');
        }

        if ($local && $wantsPdf && $needsPdfProcessorSecret) {
            $steps[] = new InstallationStep('pdf_processor_secret', 'Generate the PDF processor shared secret');
        }

        if ($local && $wantsXlsx && $needsXlsxProcessorSecret) {
            $steps[] = new InstallationStep('xlsx_processor_secret', 'Generate the XLSX processor shared secret');
        }

        if ($local && $wantsDocx && $needsDocxProcessorSecret) {
            $steps[] = new InstallationStep('docx_processor_secret', 'Generate the DOCX processor shared secret');
        }

        $steps[] = new InstallationStep('migrate', 'Run database migrations');
        $steps[] = new InstallationStep('admin', 'Create or promote the system admin');

        if ($env === 'local') {
            $steps[] = new InstallationStep('demo', 'Seed starter demo content');
            $steps[] = new InstallationStep('dev_tools', 'Show local developer tooling hints');
        }

        $steps[] = new InstallationStep('doctor', 'Run the read-only configuration doctor');
        $steps[] = new InstallationStep('login_url', 'Print the sign-in URL');

        return new InstallationPlan($local, $steps);
    }
}
