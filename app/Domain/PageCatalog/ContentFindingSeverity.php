<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

/**
 * Severity of an advisory content-scan finding: Block is refused on save,
 * Warning is recorded for operators. Scanning is advisory (see
 * PageContentScanner); isolation, not scanning, is the security boundary.
 */
enum ContentFindingSeverity: string
{
    case Block = 'block';

    case Warning = 'warning';
}
