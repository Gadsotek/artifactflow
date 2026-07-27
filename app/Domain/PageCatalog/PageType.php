<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageType: string
{
    case Markdown = 'markdown';
    case HtmlArtifact = 'html_artifact';
    case Image = 'image';

    public function usesArtifactHostPreview(): bool
    {
        return $this !== self::Markdown;
    }
}
