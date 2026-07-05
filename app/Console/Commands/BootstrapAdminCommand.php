<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\BootstrapSystemAdmin;
use Illuminate\Console\Command;

final class BootstrapAdminCommand extends Command
{
    protected $signature = 'artifactflow:bootstrap-admin {--name=} {--email=} {--password=}';

    protected $description = 'Bootstrap or promote the deployment system admin.';

    public function handle(BootstrapSystemAdmin $bootstrapSystemAdmin): int
    {
        $nameOption = $this->option('name');
        $emailOption = $this->option('email');
        $passwordOption = $this->option('password');

        $name = is_string($nameOption) ? $nameOption : '';
        $email = is_string($emailOption) ? $emailOption : '';
        $password = is_string($passwordOption) ? $passwordOption : '';

        if (trim($password) === '') {
            $configuredPassword = config('app.bootstrap_admin_password');
            $password = is_string($configuredPassword) ? $configuredPassword : '';
        }

        $user = $bootstrapSystemAdmin->handle($name, $email, $password);

        $this->info(sprintf('System admin ready: %s', $user->email));

        return 0;
    }
}
