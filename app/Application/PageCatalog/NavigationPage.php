<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class NavigationPage
{
    public function __construct(
        public string $uid,
        public string $title,
        public string $url,
        public string $type,
        public string $status,
        public ?string $workspace,
        public bool $favorite,
    ) {
    }
}
