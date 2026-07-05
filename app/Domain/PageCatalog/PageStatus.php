<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Deprecated = 'deprecated';
    case Archived = 'archived';

    public function canStartNewPage(): bool
    {
        return $this === self::Draft || $this === self::Approved;
    }

    public function returnsToDraftAfterContentChange(): bool
    {
        return $this === self::Approved || $this === self::Deprecated;
    }

    public function statusAfterWorkspaceMove(): self
    {
        return $this->returnsToDraftAfterContentChange() ? self::Draft : $this;
    }
}
