<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\PageCatalog\XlsxArtifactLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Throwable;

final readonly class XlsxUploadRules
{
    public function __construct(private XlsxArtifactLimits $limits)
    {
    }

    public function maxUploadKilobytes(): int
    {
        return intdiv($this->limits->maxUploadBytes() + 1023, 1024);
    }

    public function validateUpload(Validator $validator, string $field, ?UploadedFile $file): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $validator->errors()->add($field, 'Choose an Excel workbook to upload.');

            return null;
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            $validator->errors()->add($field, 'Excel workbook uploads must use a .xlsx file.');

            return null;
        }

        $size = $file->getSize();

        if (is_int($size) && $size > $this->limits->maxUploadBytes()) {
            $validator->errors()->add($field, 'XLSX exceeds the configured size limit.');

            return null;
        }

        try {
            $content = $file->getContent();
        } catch (Throwable) {
            $validator->errors()->add($field, 'The Excel workbook upload could not be read.');

            return null;
        }

        if (strlen($content) > $this->limits->maxUploadBytes()) {
            $validator->errors()->add($field, 'XLSX exceeds the configured size limit.');

            return null;
        }

        return $content;
    }
}
