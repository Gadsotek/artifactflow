<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class CreateTagCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $name,
    ) {
    }
}
