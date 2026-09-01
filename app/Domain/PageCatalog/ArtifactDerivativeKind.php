<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum ArtifactDerivativeKind: string
{
    case XlsxManifest = 'xlsx_manifest';
    case DocxPreviewPdf = 'docx_preview_pdf';
}
