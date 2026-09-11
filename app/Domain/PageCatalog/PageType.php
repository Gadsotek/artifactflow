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

    public function label(): string
    {
        return match ($this) {
            self::Markdown => 'Markdown',
            self::HtmlArtifact => 'HTML artifact',
            self::Image => 'Image',
            self::Pdf => 'PDF',
            self::Xlsx => 'XLSX spreadsheet',
            self::Docx => 'DOCX document',
        };
    }

    public function usesArtifactHostPreview(): bool
    {
        return match ($this) {
            self::HtmlArtifact, self::Image, self::Pdf, self::Xlsx, self::Docx => true,
            self::Markdown => false,
        };
    }
}
