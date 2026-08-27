<?php

declare(strict_types=1);

namespace App\Application\Mcp;

enum McpReadSection: string
{
    case Content = 'content';
    case Provenance = 'provenance';
}
