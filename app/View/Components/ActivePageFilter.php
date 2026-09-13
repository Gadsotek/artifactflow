<?php

declare(strict_types=1);

namespace App\View\Components;

final readonly class ActivePageFilter
{
    public function __construct(public string $key, public string $label, public string $url)
    {
    }
}
