<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Diagnostics\DeploymentDoctor;
use App\Application\Diagnostics\DoctorCheckStatus;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'artifactflow:doctor';

    protected $description = 'Read-only preflight that reports the deployment security invariants as a punch list.';

    public function handle(DeploymentDoctor $doctor): int
    {
        $report = $doctor->run();
        $this->line(sprintf('ArtifactFlow doctor (%s mode)', $report->production ? 'production' : 'local'));
        $this->newLine();

        foreach ($report->checks as $check) {
            $label = match ($check->status) {
                DoctorCheckStatus::Pass => '<info>[PASS]</info>',
                DoctorCheckStatus::Warn => '<comment>[WARN]</comment>',
                DoctorCheckStatus::Fail => '<error>[FAIL]</error>',
                DoctorCheckStatus::Skipped => '[SKIP]',
            };
            $this->line(sprintf('%s  %s: %s', $label, $check->label, $check->detail));
        }

        $this->newLine();

        if (!$report->passed()) {
            $this->error(sprintf('%d check(s) failed.', count($report->failures())));

            return 1;
        }

        $this->info('All required checks passed.');

        return 0;
    }
}
