<?php

declare(strict_types=1);

namespace App\Domain\ExternalSharing;

enum ExternalPagePresentation: string
{
    case Markdown = 'markdown';
    case SandboxedHtml = 'sandboxed_html';
    case ScriptlessImage = 'scriptless_image';
    case NativePdf = 'native_pdf';
}
