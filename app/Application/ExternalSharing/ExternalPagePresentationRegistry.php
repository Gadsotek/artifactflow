<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalPagePresentation;
use App\Domain\PageCatalog\PageType;

final class ExternalPagePresentationRegistry
{
    public function forPageType(PageType $pageType): ExternalPagePresentation
    {
        return match ($pageType) {
            PageType::Markdown => ExternalPagePresentation::Markdown,
            PageType::HtmlArtifact => ExternalPagePresentation::SandboxedHtml,
            PageType::Image => ExternalPagePresentation::ScriptlessImage,
            PageType::Pdf => ExternalPagePresentation::NativePdf,
            PageType::Xlsx => ExternalPagePresentation::TypedSpreadsheet,
            PageType::Docx => ExternalPagePresentation::DerivedDocumentPdf,
        };
    }
}
