<?php

declare(strict_types=1);

namespace App\Application\Mcp;

enum McpNotFoundResource: string
{
    case Page = 'Page';
    case Workspace = 'Workspace';
}
