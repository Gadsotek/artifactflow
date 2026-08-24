<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Domain\PageCatalog\PdfRejectionReason;
use Tests\TestCase;

final class PdfRejectionReasonTest extends TestCase
{
    public function test_each_allowlisted_reason_has_fixed_user_facing_copy(): void
    {
        $messages = [
            'active_content' => 'PDF contains active or interactive features that ArtifactFlow does not accept.',
            'encrypted' => 'Encrypted or password-protected PDFs are not accepted.',
            'input_size' => 'PDF exceeds the processor input limit.',
            'interactive_form' => 'PDF contains fillable form fields. ArtifactFlow does not accept interactive PDF forms.',
            'invalid_pdf' => 'PDF is malformed or uses unsupported document structures.',
            'invalid_eof' => 'PDF has trailing data or is missing a valid end marker.',
            'invalid_header' => 'PDF does not start with a valid PDF document header.',
            'object_limit' => 'PDF structure is too complex to process safely.',
            'page_limit' => 'PDF exceeds the 250-page processing limit.',
        ];

        foreach (PdfRejectionReason::cases() as $reason) {
            $this->assertSame($messages[$reason->value], $reason->message());
        }
    }
}
