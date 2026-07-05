<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\DiagnoseTwoFactorSecrets;
use Illuminate\Console\Command;

final class DiagnoseTwoFactorCommand extends Command
{
    protected $signature = 'artifactflow:diagnose-2fa {--json}';

    protected $description = 'Report aggregate 2FA secret decryptability after a restore without exposing secret material.';

    public function handle(DiagnoseTwoFactorSecrets $diagnoseTwoFactorSecrets): int
    {
        $result = $diagnoseTwoFactorSecrets->handle();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

            return $result->exitCode();
        }

        $this->info(sprintf(
            'Two-factor secret diagnosis: checked=%d, readable=%d, unreadable=%d.',
            $result->checked,
            $result->readable,
            $result->unreadable,
        ));

        return $result->exitCode();
    }
}
