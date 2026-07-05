<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\CreateUser;
use Illuminate\Console\Command;

final class CreateUserCommand extends Command
{
    protected $signature = 'artifactflow:create-user {--name=} {--email=} {--password=}';

    protected $description = 'Create a verified login user while registration is disabled.';

    public function handle(CreateUser $createUser): int
    {
        $nameOption = $this->option('name');
        $emailOption = $this->option('email');
        $passwordOption = $this->option('password');

        $name = is_string($nameOption) ? $nameOption : '';
        $email = is_string($emailOption) ? $emailOption : '';
        $password = is_string($passwordOption) ? $passwordOption : '';

        if (trim($password) === '') {
            $configuredPassword = config('app.create_user_password');
            $password = is_string($configuredPassword) ? $configuredPassword : '';
        }

        $user = $createUser->handle($name, $email, $password);

        $this->info(sprintf('User ready: %s', $user->email));

        return 0;
    }
}
