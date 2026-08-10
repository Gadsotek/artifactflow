<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\ImageArtifactLimits;
use App\Application\PageCatalog\RasterImageInspector;
use App\Domain\DomainRuleViolation;

/**
 * Strict JSON/Base64 adapter in front of the existing raster upload boundary.
 * It never logs or reflects submitted encoded or decoded image data.
 */
final readonly class McpImageUpload
{
    public function __construct(
        private ImageArtifactLimits $limits,
        private RasterImageInspector $inspector,
    ) {
    }

    public function decode(McpToolArguments $arguments): string
    {
        $encoded = $arguments->requiredRawString('image_base64');
        $maxEncodedBytes = 4 * intdiv($this->limits->maxUploadBytes() + 2, 3);

        if (strlen($encoded) > $maxEncodedBytes || preg_match('/\s/', $encoded) === 1) {
            throw new DomainRuleViolation('Argument [image_base64] must be canonical Base64 within the configured size limit.');
        }

        $decoded = base64_decode($encoded, true);

        if (
            !is_string($decoded)
            || $decoded === ''
            || strlen($decoded) > $this->limits->maxUploadBytes()
            || base64_encode($decoded) !== $encoded
        ) {
            throw new DomainRuleViolation('Argument [image_base64] must be canonical Base64 within the configured size limit.');
        }

        $declaredMediaType = $arguments->requiredString('media_type');
        $image = $this->inspector->inspectUpload($decoded);

        if ($image->mediaType !== $declaredMediaType) {
            throw new DomainRuleViolation('Declared image media type does not match the uploaded image.');
        }

        return $decoded;
    }
}
