<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

enum DoctorCheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';
}
