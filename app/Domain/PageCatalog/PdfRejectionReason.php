<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PdfRejectionReason: string
{
    case ActiveContent = 'active_content';
    case Encrypted = 'encrypted';
    case InputSize = 'input_size';
    case InteractiveForm = 'interactive_form';
    case InvalidDocument = 'invalid_pdf';
    case InvalidEndMarker = 'invalid_eof';
    case InvalidHeader = 'invalid_header';
    case ObjectLimit = 'object_limit';
    case PageLimit = 'page_limit';

    public function message(): string
    {
        return match ($this) {
            self::ActiveContent => 'PDF contains active or interactive features that ArtifactFlow does not accept.',
            self::Encrypted => 'Encrypted or password-protected PDFs are not accepted.',
            self::InputSize => 'PDF exceeds the processor input limit.',
            self::InteractiveForm => 'PDF contains fillable form fields. ArtifactFlow does not accept interactive PDF forms.',
            self::InvalidDocument => 'PDF is malformed or uses unsupported document structures.',
            self::InvalidEndMarker => 'PDF has trailing data or is missing a valid end marker.',
            self::InvalidHeader => 'PDF does not start with a valid PDF document header.',
            self::ObjectLimit => 'PDF structure is too complex to process safely.',
            self::PageLimit => 'PDF exceeds the 250-page processing limit.',
        };
    }
}
