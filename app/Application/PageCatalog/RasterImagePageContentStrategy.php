<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use LogicException;

final readonly class RasterImagePageContentStrategy implements PageContentStrategy
{
    public function __construct(
        private ImageArtifactLimits $limits,
        private RasterImageInspector $inspector,
        private RasterImageNormalizer $normalizer,
        private ImageParserConfiguration $parserConfiguration,
    ) {
    }

    public function supportedTypes(): array
    {
        return [PageType::Image];
    }

    public function validateInput(PageType $type, string $content): void
    {
        $this->ensureSupported($type);

        if (!$this->parserConfiguration->enabled()) {
            throw new DomainRuleViolation('Image artifacts are disabled for this installation.');
        }

        $this->ensurePresent($content);

        if (strlen($content) > $this->limits->maxUploadBytes()) {
            throw new DomainRuleViolation('Page content exceeds the configured size limit.');
        }
    }

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void
    {
        $this->ensureSupported($type);

        if ($sourceFilename === null) {
            return;
        }

        $extension = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));

        if (!in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            throw new DomainRuleViolation('Image uploads must use a .png, .jpg, or .jpeg file.');
        }
    }

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        $this->ensureSupported($type);

        if ($source === PageVersionSource::Restore) {
            // A restore must reproduce the immutable derivative exactly. Re-encoding
            // JPEG would add another lossy generation, so validate retained bytes
            // against the permanent read envelope and keep them unchanged.
            $this->ensurePresent($content);
            $image = new NormalizedRasterImage($content, $this->inspector->inspectStored($content));
        } else {
            $this->validateInput($type, $content);
            // Native decoding, admission locking, and the parser network round trip
            // live at this explicit preparation boundary, outside the transaction.
            $image = $this->normalizer->normalizeWithInfo($content, $actorUid);
        }

        if (strlen($image->bytes) > $this->limits->maxStoredBytes()) {
            throw new DomainRuleViolation('Page content exceeds the configured size limit.');
        }

        return new PreparedPageContent(
            content: $image->bytes,
            scan: new ContentSecurityScan([], []),
            storageFilename: 'preview.' . $image->info->extension(),
            textProjection: $this->textProjection($type, $image->bytes),
        );
    }

    public function textProjection(PageType $type, string $content): PageContentTextProjection
    {
        $this->ensureSupported($type);

        return new PageContentTextProjection(null, null);
    }

    public function requiresContentForTextProjection(PageType $type): bool
    {
        $this->ensureSupported($type);

        return false;
    }

    private function ensurePresent(string $content): void
    {
        if ($content === '') {
            throw new DomainRuleViolation('Page content must not be blank.');
        }
    }

    private function ensureSupported(PageType $type): void
    {
        if ($type !== PageType::Image) {
            throw new LogicException(sprintf(
                'Raster image page content strategy does not support [%s].',
                $type->value,
            ));
        }
    }
}
