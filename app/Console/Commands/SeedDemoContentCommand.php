<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\PageCatalog\SeedDemoContent;
use App\Domain\DomainRuleViolation;
use App\Models\User;
use Illuminate\Console\Command;

final class SeedDemoContentCommand extends Command
{
    protected $signature = 'artifactflow:seed-demo-content {--email=}';

    protected $description = 'Seed Hello World Markdown and HTML artifact pages for a user.';

    public function handle(SeedDemoContent $seedDemoContent): int
    {
        $emailOption = $this->option('email');
        $email = is_string($emailOption) ? strtolower(trim($emailOption)) : '';

        if ($email === '') {
            throw new DomainRuleViolation('User email is required.');
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (!$user instanceof User) {
            throw new DomainRuleViolation('User does not exist.');
        }

        $seedDemoContent->handle($user);

        $this->info(sprintf('Demo content ready for %s', $user->email));

        return 0;
    }
}
