<?php

declare(strict_types=1);

namespace App\Domain\Identity;

enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
