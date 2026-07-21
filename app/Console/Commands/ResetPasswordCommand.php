<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\OneShotPasswordFile;
use App\Application\Identity\ResetUserPassword;
use App\Domain\DomainRuleViolation;
use App\Models\User;
use Illuminate\Console\Command;

final class ResetPasswordCommand extends Command
{
    protected $signature = 'artifactflow:reset-password {--email=} {--password=}';

    protected $description = 'Reset a user password and invalidate existing sessions while registration is disabled.';

    public function handle(ResetUserPassword $resetUserPassword, OneShotPasswordFile $passwordFile): int
    {
        $emailOption = $this->option('email');
        $passwordOption = $this->option('password');

        $email = is_string($emailOption) ? strtolower(trim($emailOption)) : '';
        $password = $passwordFile->read('ARTIFACTFLOW_RESET_PASSWORD_FILE')
            ?? (is_string($passwordOption) ? $passwordOption : '');

        if (trim($password) === '') {
            $configuredPassword = config('app.reset_user_password');
            $password = is_string($configuredPassword) ? $configuredPassword : '';
        }

        try {
            $user = User::query()
                ->where('email', $email)
                ->first();

            if (!$user instanceof User) {
                throw new DomainRuleViolation('User does not exist.');
            }

            $resetUserPassword->handle($user, $password);
        } catch (DomainRuleViolation $exception) {
            $this->line($exception->getMessage());

            return 1;
        }

        $this->info(sprintf('Password reset for %s', $user->email));

        return 0;
    }
}
