<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\ExternalShare;
use App\Models\Page;

final readonly class ExternalShareExchangeResult
{
    public function __construct(
        public ExternalShare $share,
        public Page $page,
        public IssuedExternalShareSession $issuedSession,
        public bool $acknowledgementRequired,
        public ?string $csrfToken,
    ) {
    }
}
