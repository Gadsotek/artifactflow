<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\DomainRuleViolation;

final readonly class OneShotPasswordFile
{
    public function read(string $environmentVariable): ?string
    {
        $configuredPath = getenv($environmentVariable);

        if (!is_string($configuredPath) || trim($configuredPath) === '') {
            return null;
        }

        $path = trim($configuredPath);
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if (!is_string($contents)) {
            throw new DomainRuleViolation(sprintf('%s must point to a readable secret file.', $environmentVariable));
        }

        $password = rtrim($contents, "\r\n");

        if ($password === '') {
            throw new DomainRuleViolation(sprintf('%s must not point to an empty secret file.', $environmentVariable));
        }

        return $password;
    }
}
