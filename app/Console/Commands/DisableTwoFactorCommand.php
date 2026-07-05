<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\DisableTwoFactorForOperator;
use App\Domain\DomainRuleViolation;
use Illuminate\Console\Command;
use RuntimeException;

final class DisableTwoFactorCommand extends Command
{
    protected $signature = 'artifactflow:disable-2fa {--email=} {--force} {--reason=} {--clear-enforcement}';

    protected $description = 'Disable two-factor authentication for a user from the real CLI.';

    public function handle(DisableTwoFactorForOperator $disableTwoFactorForOperator): int
    {
        $emailOption = $this->option('email');
        $reasonOption = $this->option('reason');

        $email = is_string($emailOption) ? strtolower(trim($emailOption)) : '';
        $reason = is_string($reasonOption) ? $reasonOption : '';

        try {
            $user = $disableTwoFactorForOperator->handle(
                email: $email,
                reason: $reason,
                clearEnforcement: (bool) $this->option('clear-enforcement'),
                force: (bool) $this->option('force'),
            );
        } catch (DomainRuleViolation|RuntimeException $exception) {
            $this->line($exception->getMessage());

            return 1;
        }

        $this->info(sprintf('Two-factor authentication disabled for %s.', $user->email));

        return 0;
    }
}
