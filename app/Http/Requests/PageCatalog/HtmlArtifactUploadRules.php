<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Domain\PageCatalog\PageContentEncoding;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

final class HtmlArtifactUploadRules
{
    /**
     * @var list<string>
     */
    private const array MIME_TYPES = ['text/html', 'text/plain', 'application/xhtml+xml'];

    /**
     * Kilobyte ceiling for the `max:` file rule, so oversized uploads are
     * rejected from filesystem metadata before any content is read.
     */
    public function maxUploadKilobytes(int $maxBytes): int
    {
        return max(1, (int) ceil($maxBytes / 1024));
    }

    public function validateUpload(Validator $validator, string $field, ?UploadedFile $file, int $maxBytes): void
    {
        if (!$file instanceof UploadedFile) {
            $validator->errors()->add($field, 'An HTML file is required.');

            return;
        }

        if (strtolower($file->getClientOriginalExtension()) !== 'html') {
            $validator->errors()->add($field, 'HTML artifact uploads must use a .html file.');
        }

        $size = $file->getSize();

        if (!is_int($size) || $size > $maxBytes) {
            $validator->errors()->add($field, 'HTML file exceeds the configured size limit.');

            return;
        }

        $content = $file->getContent();
        $this->validateBytes($validator, $field, $file, $content);
        $this->validateDocumentContent($validator, $field, $content);
    }

    public function validateDocumentContent(Validator $validator, string $field, string $content): void
    {
        if (preg_match('/^\s*(?:<!doctype\s+html\b|<html\b)/i', $content) !== 1) {
            $validator->errors()->add($field, 'HTML artifacts must start with an HTML document.');
        }
    }

    private function validateBytes(Validator $validator, string $field, UploadedFile $file, string $content): void
    {
        $mimeType = $file->getMimeType();

        if (!is_string($mimeType) || !in_array($mimeType, self::MIME_TYPES, true)) {
            $validator->errors()->add($field, 'HTML artifact uploads must be text/html.');
        }

        if (!PageContentEncoding::isValidUtf8($content)) {
            $validator->errors()->add($field, 'HTML artifact uploads must be valid UTF-8.');

            return;
        }

        if (PageContentEncoding::containsControlBytes($content)) {
            $validator->errors()->add($field, 'HTML artifact uploads must be text, not binary content.');
        }
    }
}
