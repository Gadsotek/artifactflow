<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use JsonException;
use RuntimeException;

final readonly class XlsxProcessingResult
{
    public function __construct(
        public string $responseSchema,
        public string $processorProfile,
        public string $engineName,
        public string $engineVersion,
        public int $packageEntryCount,
        public int $expandedBytes,
        public string $manifestJson,
        public string $searchText,
        public int $visibleSheetCount,
        public int $omittedHiddenSheetCount,
        public int $projectedRowExtentCount,
        public int $projectedColumnExtentCount,
        public int $omittedHiddenRowCount,
        public int $omittedHiddenColumnCount,
        public int $cellCount,
        public int $formulaCount,
        public int $formulasWithoutCachedResultCount,
        public int $linkCount,
        public int $mergeCount,
        public bool $truncated,
    ) {
    }

    public static function fromJson(
        string $json,
        int $expectedInputBytes,
        string $expectedInputSha256,
    ): self {
        if (strlen($json) > XlsxProcessorConfiguration::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('XLSX processor response exceeds its byte limit.');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('XLSX processor returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('XLSX processor response has an invalid shape.');
        }

        self::assertExactKeys($decoded, ['engine', 'input', 'manifest', 'package', 'profile', 'schema']);
        $engine = self::object($decoded['engine'] ?? null);
        $input = self::object($decoded['input'] ?? null);
        $package = self::object($decoded['package'] ?? null);
        $manifest = self::object($decoded['manifest'] ?? null);
        self::assertExactKeys($engine, ['name', 'version']);
        self::assertExactKeys($input, ['bytes', 'sha256']);
        self::assertExactKeys($package, ['entryCount', 'expandedBytes']);

        $responseSchema = $decoded['schema'] ?? null;
        $processorProfile = $decoded['profile'] ?? null;
        $engineName = $engine['name'] ?? null;
        $engineVersion = $engine['version'] ?? null;
        $inputBytes = $input['bytes'] ?? null;
        $inputSha256 = $input['sha256'] ?? null;
        $entryCount = $package['entryCount'] ?? null;
        $expandedBytes = $package['expandedBytes'] ?? null;

        if (
            $responseSchema !== XlsxProcessorProtocol::RESPONSE_SCHEMA
            || $processorProfile !== XlsxProcessorProtocol::PROCESSOR_PROFILE
            || $engineName !== XlsxProcessorProtocol::ENGINE_NAME
            || $engineVersion !== XlsxProcessorProtocol::ENGINE_VERSION
            || !is_int($inputBytes)
            || $inputBytes !== $expectedInputBytes
            || !is_string($inputSha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $inputSha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedInputSha256) !== 1
            || !hash_equals($expectedInputSha256, $inputSha256)
            || !is_int($entryCount)
            || $entryCount < 1
            || $entryCount > XlsxProcessorConfiguration::MAX_ENTRIES
            || !is_int($expandedBytes)
            || $expandedBytes < 1
            || $expandedBytes > XlsxProcessorConfiguration::MAX_EXPANDED_BYTES
        ) {
            throw new RuntimeException('XLSX processor response contains invalid facts.');
        }

        $validated = (new XlsxManifestValidator())->validate($manifest);

        return new self(
            responseSchema: $responseSchema,
            processorProfile: $processorProfile,
            engineName: $engineName,
            engineVersion: $engineVersion,
            packageEntryCount: $entryCount,
            expandedBytes: $expandedBytes,
            manifestJson: $validated->manifestJson,
            searchText: $validated->searchText,
            visibleSheetCount: $validated->visibleSheetCount,
            omittedHiddenSheetCount: $validated->omittedHiddenSheetCount,
            projectedRowExtentCount: $validated->projectedRowExtentCount,
            projectedColumnExtentCount: $validated->projectedColumnExtentCount,
            omittedHiddenRowCount: $validated->omittedHiddenRowCount,
            omittedHiddenColumnCount: $validated->omittedHiddenColumnCount,
            cellCount: $validated->cellCount,
            formulaCount: $validated->formulaCount,
            formulasWithoutCachedResultCount: $validated->formulasWithoutCachedResultCount,
            linkCount: $validated->linkCount,
            mergeCount: $validated->mergeCount,
            truncated: $validated->truncated,
        );
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new RuntimeException('XLSX processor response contains unexpected fields.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('XLSX processor response contains an invalid object.');
        }

        /** @var array<string, mixed> $object */
        $object = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException('XLSX processor response contains an invalid object.');
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
