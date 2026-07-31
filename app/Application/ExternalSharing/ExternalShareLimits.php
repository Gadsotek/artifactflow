<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\PageCatalog\PositiveIntegerConfiguration;
use LogicException;

final class ExternalShareLimits
{
    public function maxActivePerPage(): int
    {
        return $this->positive('external_sharing.max_active_per_page');
    }

    public function maxActivePerInstallation(): int
    {
        return $this->positive('external_sharing.max_active_per_installation');
    }

    public function maxViewSessionsPerShare(): int
    {
        return $this->positive('external_sharing.max_view_sessions_per_share');
    }

    private function positive(string $key): int
    {
        $value = PositiveIntegerConfiguration::tryFrom(config($key));

        if ($value === null) {
            throw new LogicException(sprintf('Configured external sharing limit [%s] must be positive.', $key));
        }

        return $value;
    }
}
