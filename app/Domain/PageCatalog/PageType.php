<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PageType: string
{
    case Markdown = 'markdown';
    case HtmlArtifact = 'html_artifact';
    case Image = 'image';
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
    case Docx = 'docx';

    public function usesArtifactHostPreview(): bool
    {
        return match ($this) {
            self::HtmlArtifact, self::Image, self::Pdf, self::Xlsx, self::Docx => true,
            self::Markdown => false,
        };
    }
}
