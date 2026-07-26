<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\PageCatalog\ImageArtifactLimits;
use App\Application\PageCatalog\RasterImageInspector;
use App\Domain\DomainRuleViolation;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

final readonly class RasterImageUploadRules
{
    public function __construct(
        private ImageArtifactLimits $limits,
        private RasterImageInspector $inspector,
    ) {
    }

    public function maxUploadKilobytes(): int
    {
        return max(1, (int) ceil($this->limits->maxUploadBytes() / 1024));
    }

    public function validateUpload(Validator $validator, string $field, ?UploadedFile $file): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $validator->errors()->add($field, 'Choose a PNG or JPEG image to upload.');

            return null;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $size = $file->getSize();

        if (!is_int($size) || $size > $this->limits->maxUploadBytes()) {
            $validator->errors()->add($field, 'Image exceeds the configured size limit.');

            return null;
        }

        $bytes = $file->getContent();

        try {
            $info = $this->inspector->inspectUpload($bytes);
        } catch (DomainRuleViolation $exception) {
            $validator->errors()->add($field, $exception->getMessage());

            return null;
        }

        $expectedExtensions = $info->mediaType === 'image/jpeg' ? ['jpg', 'jpeg'] : ['png'];

        if (!in_array($extension, $expectedExtensions, true)) {
            $validator->errors()->add($field, 'Image extension and detected PNG/JPEG format must match.');

            return null;
        }

        return $bytes;
    }
}
