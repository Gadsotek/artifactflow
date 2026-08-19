<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PdfArtifactPurpose: string
{
    case Current = 'current';
    case History = 'history';
    case Download = 'download';
}
