<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\PdfExtractionState;
use JsonException;
use RuntimeException;

final readonly class PdfProcessingResult
{
    public const string PROCESSOR_PROFILE = 'pdfbox-3.0.8-native-text-v1';

    public const string DOCX_PREVIEW_PROCESSOR_PROFILE = 'pdfbox-3.0.8-docx-preview-v1';

    public function __construct(
        public int $pageCount,
        public string $pdfVersion,
        public PdfExtractionState $extractionState,
        public string $processorProfile,
        public string $text,
    ) {
    }

    public static function fromJson(
        string $json,
        string $expectedProfile = self::PROCESSOR_PROFILE,
    ): self {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('PDF processor returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('PDF processor response has an invalid shape.');
        }

        $keys = array_keys($decoded);
        sort($keys);

        if ($keys !== ['extraction_state', 'page_count', 'pdf_version', 'processor_profile', 'text']) {
            throw new RuntimeException('PDF processor response has unexpected fields.');
        }

        $pageCount = $decoded['page_count'] ?? null;
        $pdfVersion = $decoded['pdf_version'] ?? null;
        $stateValue = $decoded['extraction_state'] ?? null;
        $processorProfile = $decoded['processor_profile'] ?? null;
        $text = $decoded['text'] ?? null;
        $state = is_string($stateValue) ? PdfExtractionState::tryFrom($stateValue) : null;

        if (
            !is_int($pageCount)
            || $pageCount < 1
            || $pageCount > 250
            || !is_string($pdfVersion)
            || preg_match('/^(?:1\.[0-7]|2\.0)$/D', $pdfVersion) !== 1
            || !$state instanceof PdfExtractionState
            || !in_array($expectedProfile, [self::PROCESSOR_PROFILE, self::DOCX_PREVIEW_PROCESSOR_PROFILE], true)
            || $processorProfile !== $expectedProfile
            || !is_string($text)
            || strlen($text) > PdfProcessorConfiguration::MAX_TEXT_BYTES
            || !PageContentEncoding::isStorable($text)
            || ($state === PdfExtractionState::NoEmbeddedText && trim($text) !== '')
            || ($state === PdfExtractionState::Indexed && trim($text) === '')
        ) {
            throw new RuntimeException('PDF processor response contains invalid values.');
        }

        return new self(
            pageCount: $pageCount,
            pdfVersion: $pdfVersion,
            extractionState: $state,
            processorProfile: $processorProfile,
            text: $text,
        );
    }
}
