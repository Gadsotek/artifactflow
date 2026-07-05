<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\PageCatalog\VerifyArtifactIntegrity;
use Illuminate\Console\Command;

final class VerifyArtifactIntegrityCommand extends Command
{
    protected $signature = 'artifactflow:verify-artifacts {--sample=25} {--all} {--json}';

    protected $description = 'Verify stored page-version artifact files exist and match their recorded SHA-256 hashes.';

    public function handle(VerifyArtifactIntegrity $verifyArtifactIntegrity): int
    {
        $sampleOption = $this->input->getOption('sample');
        $sampleSize = 25;

        if (is_string($sampleOption) && $sampleOption !== '') {
            $sampleSize = ctype_digit($sampleOption) ? (int) $sampleOption : 0;
        } elseif (is_int($sampleOption)) {
            $sampleSize = $sampleOption;
        }

        if ($sampleSize < 1) {
            $this->line('Artifact verification sample size must be positive.');

            return 1;
        }

        $result = $verifyArtifactIntegrity->handle(
            sampleSize: $sampleSize,
            all: (bool) $this->option('all'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

            return $result->hasFailures() ? 1 : 0;
        }

        $this->info(sprintf(
            'Artifact verification complete: checked=%d, ok=%d, missing_file=%d, hash_mismatch=%d.',
            $result->checked,
            $result->ok,
            $result->missingFile,
            $result->hashMismatch,
        ));

        return $result->hasFailures() ? 1 : 0;
    }
}
