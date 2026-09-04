<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\PageCatalog\DocxArtifactLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Throwable;

final readonly class DocxUploadRules
{
    public function __construct(private DocxArtifactLimits $limits)
    {
    }

    public function maxUploadKilobytes(): int
    {
        return intdiv($this->limits->maxUploadBytes() + 1023, 1024);
    }

    public function validateUpload(Validator $validator, string $field, ?UploadedFile $file): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $validator->errors()->add($field, 'Choose a Word document to upload.');

            return null;
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'docx') {
            $validator->errors()->add($field, 'Word document uploads must use a .docx file.');

            return null;
        }
        $size = $file->getSize();
        if (is_int($size) && $size > $this->limits->maxUploadBytes()) {
            $validator->errors()->add($field, 'DOCX exceeds the configured size limit.');

            return null;
        }
        try {
            $content = $file->getContent();
        } catch (Throwable) {
            $validator->errors()->add($field, 'The Word document upload could not be read.');

            return null;
        }
        if (strlen($content) > $this->limits->maxUploadBytes()) {
            $validator->errors()->add($field, 'DOCX exceeds the configured size limit.');

            return null;
        }

        return $content;
    }
}
