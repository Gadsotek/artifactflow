<?php

declare(strict_types=1);

namespace App\Application\Administration;

final class ByteSizeFormatter
{
    public function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MiB', $bytes / (1024 * 1024));
        }

        return sprintf('%.1f GiB', $bytes / (1024 * 1024 * 1024));
    }
}
