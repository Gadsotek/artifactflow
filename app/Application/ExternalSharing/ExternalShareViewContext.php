<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\Page;

final readonly class ExternalShareViewContext
{
    public function __construct(
        public ExternalShare $share,
        public Page $page,
        public ExternalShareSession $session,
    ) {
    }
}
