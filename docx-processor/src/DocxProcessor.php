<?php

declare(strict_types=1);

namespace ArtifactFlow\DocxProcessor;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

final class ProcessorRejection extends RuntimeException
{
    private ?string $diagnosticContextCode = null;

    private string $publicReasonCode = 'invalid_document';

    public static function withDiagnosticContext(
        string $message,
        string $context,
        string $publicReasonCode = 'invalid_document',
    ): self
    {
        $exception = new self($message);
        $exception->diagnosticContextCode = substr(hash('sha256', $context), 0, 12);
        $exception->publicReasonCode = $publicReasonCode;

        return $exception;
    }

    public function diagnosticCode(): string
    {
        return substr(hash('sha256', $this->getMessage()), 0, 12);
    }

    public function diagnosticContextCode(): ?string
    {
        return $this->diagnosticContextCode;
    }

    public function publicReasonCode(): string
    {
        return $this->publicReasonCode;
    }
}

final class ProcessorAuthenticationFailure extends RuntimeException
{
}

final class ProcessorUnavailable extends RuntimeException
{
    public function diagnosticCode(): string
    {
        return substr(hash('sha256', $this->getMessage()), 0, 12);
    }
}

final readonly class ProcessorConfiguration
{
    public const int MAX_INPUT_BYTES = 16 * 1024 * 1024;

    public const int MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

    public const int MAX_EXPANDED_BYTES = 64 * 1024 * 1024;

    public const int MAX_ENTRIES = 2_000;

    public function __construct(
        public string $sharedSecret,
        public int $maxClockSkewSeconds = 120,
    ) {
        if (strlen($this->sharedSecret) < 32) {
            throw new ProcessorUnavailable('DOCX processor authentication is not configured.');
        }
    }

    public static function fromEnvironment(): self
    {
        $secret = getenv('DOCX_PROCESSOR_SHARED_SECRET');
        $secret = is_string($secret) ? trim($secret) : '';

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            $secret = is_string($decoded) ? $decoded : '';
        }

        $clockSkew = getenv('DOCX_PROCESSOR_MAX_CLOCK_SKEW_SECONDS');
        $clockSkew = is_string($clockSkew) && ctype_digit($clockSkew) ? (int) $clockSkew : 120;

        if ($clockSkew < 1 || $clockSkew > 300) {
            throw new ProcessorUnavailable('DOCX processor clock-skew configuration is invalid.');
        }

        return new self($secret, $clockSkew);
    }
}

final readonly class ProcessorRequest
{
    public const string PROFILE = 'docx-passive-pdf-v1';

    public const string MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        public string $nonce,
        public string $inputSha256,
        public string $bytes,
    ) {
    }

    /** @param array<string, string> $server */
    public static function authenticated(
        ProcessorConfiguration $configuration,
        array $server,
        string $bytes,
        ?int $now = null,
    ): self {
        $timestamp = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP'] ?? '';
        $nonce = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE'] ?? '';
        $profile = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_PROFILE'] ?? '';
        $declaredHash = $server['HTTP_X_ARTIFACTFLOW_INPUT_SHA256'] ?? '';
        $signature = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE'] ?? '';
        $contentType = $server['CONTENT_TYPE'] ?? '';
        $contentLength = (string) self::validatedDeclaredLength($server);

        if (
            preg_match('/\A[1-9][0-9]{0,9}\z/', $timestamp) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $nonce) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $declaredHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
            || $profile !== self::PROFILE
            || $contentType !== self::MEDIA_TYPE
            || $bytes === ''
            || strlen($bytes) > ProcessorConfiguration::MAX_INPUT_BYTES
            || (int) $contentLength !== strlen($bytes)
        ) {
            throw new ProcessorAuthenticationFailure('Unauthenticated DOCX processor request.');
        }

        $inputSha256 = hash('sha256', $bytes);
        $expected = hash_hmac('sha256', implode("\n", [
            'artifactflow-docx-processor-request-v1',
            $timestamp,
            $nonce,
            $profile,
            $contentType,
            $contentLength,
            $inputSha256,
        ]), $configuration->sharedSecret);

        if (!hash_equals($declaredHash, $inputSha256) || !hash_equals($expected, $signature)) {
            throw new ProcessorAuthenticationFailure('Unauthenticated DOCX processor request.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $configuration->maxClockSkewSeconds) {
            throw new ProcessorAuthenticationFailure('DOCX processor request is outside the clock-skew window.');
        }

        ProcessorReplayCache::claim($nonce, $configuration->maxClockSkewSeconds);

        return new self($nonce, $inputSha256, $bytes);
    }

    public static function fromGlobals(ProcessorConfiguration $configuration): self
    {
        /** @var array<string, string> $server */
        $server = array_filter($_SERVER, static fn (mixed $value): bool => is_string($value));
        $declaredLength = self::validatedDeclaredLength($server);
        $bytes = file_get_contents('php://input', false, null, 0, $declaredLength + 1);

        if (!is_string($bytes)) {
            throw new ProcessorRejection('DOCX input could not be read.');
        }

        return self::authenticated($configuration, $server, $bytes);
    }

    /** @param array<string, string> $server */
    public static function validatedDeclaredLength(array $server): int
    {
        $contentLength = $server['CONTENT_LENGTH'] ?? '';
        if (preg_match('/\A[1-9][0-9]{0,8}\z/', $contentLength) !== 1) {
            throw new ProcessorAuthenticationFailure('Unauthenticated DOCX processor request.');
        }

        $declaredLength = (int) $contentLength;
        if ($declaredLength > ProcessorConfiguration::MAX_INPUT_BYTES) {
            throw new ProcessorAuthenticationFailure('Unauthenticated DOCX processor request.');
        }

        return $declaredLength;
    }
}

final readonly class ProcessorHealthRequest
{
    private const string CONTEXT = 'artifactflow-docx-processor-health-v1';

    private const string RESPONSE_CONTEXT = 'artifactflow-docx-processor-health-response-v1';

    public function __construct(public string $nonce)
    {
    }

    /** @param array<string, string> $server */
    public static function authenticated(
        ProcessorConfiguration $configuration,
        array $server,
        ?int $now = null,
    ): self {
        $timestamp = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP'] ?? '';
        $nonce = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE'] ?? '';
        $signature = $server['HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE'] ?? '';

        if (
            preg_match('/\A[1-9][0-9]{0,9}\z/', $timestamp) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $nonce) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
            || !hash_equals(self::signature($timestamp, $nonce, $configuration->sharedSecret), $signature)
            || abs(($now ?? time()) - (int) $timestamp) > $configuration->maxClockSkewSeconds
        ) {
            throw new ProcessorAuthenticationFailure('Unauthenticated DOCX processor health request.');
        }

        ProcessorReplayCache::claim($nonce, $configuration->maxClockSkewSeconds);

        return new self($nonce);
    }

    public static function fromGlobals(ProcessorConfiguration $configuration): self
    {
        /** @var array<string, string> $server */
        $server = array_filter($_SERVER, static fn (mixed $value): bool => is_string($value));

        return self::authenticated($configuration, $server);
    }

    /** @return array<string, string> */
    public static function signedHeaders(ProcessorConfiguration $configuration): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-ArtifactFlow-Processor-Timestamp' => $timestamp,
            'X-ArtifactFlow-Processor-Nonce' => $nonce,
            'X-ArtifactFlow-Processor-Signature' => self::signature(
                $timestamp,
                $nonce,
                $configuration->sharedSecret,
            ),
        ];
    }

    public static function signature(string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [
            self::CONTEXT,
            $timestamp,
            $nonce,
            'GET',
            '/health',
        ]), $secret);
    }

    public static function responseSignature(string $nonce, string $responseBody, string $secret): string
    {
        return hash_hmac('sha256', implode("\n", [
            self::RESPONSE_CONTEXT,
            $nonce,
            'application/json',
            (string) strlen($responseBody),
            hash('sha256', $responseBody),
        ]), $secret);
    }
}

final readonly class ProcessorContainment
{
    public static function verifyNetworkIsolation(): void
    {
        $interfaceStates = glob('/sys/class/net/*/operstate', GLOB_NOSORT);
        if (is_array($interfaceStates) && $interfaceStates !== []) {
            foreach ($interfaceStates as $interfaceState) {
                $interface = basename(dirname($interfaceState));
                if ($interface === 'lo') {
                    continue;
                }

                $operationalState = @file_get_contents($interfaceState);
                if (! is_string($operationalState) || trim($operationalState) !== 'down') {
                    throw new ProcessorUnavailable('DOCX processor network isolation is not active.');
                }
            }

            return;
        }

        $interfaces = scandir('/sys/class/net');
        if (!is_array($interfaces)) {
            throw new ProcessorUnavailable('DOCX processor containment state is unavailable.');
        }

        $interfaces = array_values(array_filter(
            $interfaces,
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
        if ($interfaces !== ['lo']) {
            throw new ProcessorUnavailable('DOCX processor network isolation is not active.');
        }
    }
}

final class ProcessorReplayCache
{
    public static function claim(string $nonce, int $ttlSeconds): void
    {
        $directory = '/tmp/artifactflow-docx-nonces';

        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new ProcessorUnavailable('DOCX replay cache is unavailable.');
        }

        $now = time();
        foreach (glob($directory . '/*') ?: [] as $candidate) {
            $mtime = filemtime($candidate);
            if (is_int($mtime) && $mtime + $ttlSeconds < $now) {
                unlink($candidate);
            }
        }

        $handle = @fopen($directory . '/' . $nonce, 'x');

        if (!is_resource($handle)) {
            throw new ProcessorAuthenticationFailure('DOCX processor request nonce was already used.');
        }

        fclose($handle);
    }
}

final readonly class PackageFacts
{
    public function __construct(
        public int $entryCount,
        public int $expandedBytes,
        public int $relationshipCount,
        public int $mediaCount,
        public int $externalHyperlinkCount,
    ) {
    }
}

final readonly class DocxPackageInspector
{
    private const int MAX_CENTRAL_DIRECTORY_BYTES = 2 * 1024 * 1024;

    private const int MAX_MEDIA_BYTES = 32 * 1024 * 1024;

    private const int MAX_MEDIA_COUNT = 1_024;

    private const int MAX_MEDIA_DIMENSION = 32_768;

    private const int MAX_MEDIA_PIXELS = 16 * 1024 * 1024;

    private const int MAX_AGGREGATE_MEDIA_PIXELS = 256 * 1024 * 1024;

    private const int MAX_EMF_RECORDS = 250_000;

    private const int MAX_EMF_HANDLES = 16_384;

    private const int MAX_EMBEDDED_FONT_BYTES = 32 * 1024 * 1024;

    private const int MAX_EMBEDDED_FONT_COUNT = 64;

    private const int MAX_SINGLE_EMBEDDED_FONT_BYTES = 16 * 1024 * 1024;

    private const int MAX_CUSTOM_XML_BYTES = 32 * 1024 * 1024;

    private const int MAX_CUSTOM_XML_COUNT = 128;

    private const int MAX_SINGLE_CUSTOM_XML_BYTES = 8 * 1024 * 1024;

    private const array PROHIBITED_EMF_RECORD_TYPES = [102, 103, 105, 106, 107, 110];

    private const string CONTENT_TYPES_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const string RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const array WORDPROCESSING_NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];

    private const string OFFICE_RELATIONSHIP_PREFIX = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private const string STRICT_OFFICE_RELATIONSHIP_PREFIX = 'http://purl.oclc.org/ooxml/officeDocument/relationships/';

    private const array ALLOWED_OFFICE_RELATIONSHIP_KINDS = [
        'comments',
        'custom-properties',
        'customXml',
        'customXmlProps',
        'endnotes',
        'extended-properties',
        'font',
        'fontTable',
        'footer',
        'footnotes',
        'header',
        'hyperlink',
        'image',
        'numbering',
        'officeDocument',
        'person',
        'settings',
        'styles',
        'theme',
        'webSettings',
    ];

    private const array MICROSOFT_RELATIONSHIP_KINDS = [
        'http://schemas.microsoft.com/office/2007/relationships/stylesWithEffects' => 'stylesWithEffects',
        'http://schemas.microsoft.com/office/2011/relationships/commentsExtended' => 'commentsExtended',
        'http://schemas.microsoft.com/office/2011/relationships/person' => 'person',
        'http://schemas.microsoft.com/office/2016/09/relationships/commentsIds' => 'commentsIds',
    ];

    private const array REQUIRED_ENTRIES = [
        '[Content_Types].xml',
        '_rels/.rels',
        'word/document.xml',
    ];

    public function inspect(string $bytes): PackageFacts
    {
        if (!str_starts_with($bytes, "PK\x03\x04")) {
            throw new ProcessorRejection('The upload is not a DOCX ZIP package.');
        }
        $expectedEntryCount = $this->validateZipBoundary($bytes);

        $path = tempnam('/tmp', 'artifactflow-docx-package-');
        if (!is_string($path)) {
            throw new ProcessorUnavailable('DOCX package staging failed.');
        }

        try {
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, 0600)) {
                throw new ProcessorUnavailable('DOCX package staging failed.');
            }

            $archive = new ZipArchive();
            $opened = $archive->open($path, ZipArchive::CHECKCONS);
            if (
                $opened !== true
                || $archive->numFiles !== $expectedEntryCount
                || $archive->numFiles < 1
                || $archive->numFiles > ProcessorConfiguration::MAX_ENTRIES
            ) {
                throw new ProcessorRejection('The DOCX ZIP structure is invalid or excessive.');
            }

            try {
                return $this->inspectArchive($archive);
            } finally {
                $archive->close();
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function inspectArchive(ZipArchive $archive): PackageFacts
    {
        $names = [];
        $folded = [];
        $expandedBytes = 0;
        $relationshipCount = 0;
        $mediaCount = 0;
        $mediaBytes = 0;
        $mediaPixels = 0;
        $externalHyperlinkCount = 0;
        $embeddedFontCount = 0;
        $embeddedFontBytes = 0;
        $customXmlCount = 0;
        $customXmlBytes = 0;
        $relationshipDocuments = [];
        $relationshipTargetsBySource = [];
        $contentTypes = null;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)) {
                throw new ProcessorRejection('The DOCX ZIP directory is invalid.');
            }

            $name = $stat['name'] ?? null;
            $size = $stat['size'] ?? null;
            $compressedSize = $stat['comp_size'] ?? null;
            $compressionMethod = $stat['comp_method'] ?? null;
            $encryptionMethod = $stat['encryption_method'] ?? ZipArchive::EM_NONE;
            $operatingSystem = 0;
            $externalAttributes = 0;
            $hasExternalAttributes = $archive->getExternalAttributesIndex(
                $index,
                $operatingSystem,
                $externalAttributes,
                ZipArchive::FL_UNCHANGED,
            );
            $unixFileType = $operatingSystem === ZipArchive::OPSYS_UNIX
                ? ($externalAttributes >> 16) & 0170000
                : 0;

            if (!is_string($name)) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an invalid entry name.');
            }
            $isDirectory = str_ends_with($name, '/');
            $partName = $isDirectory ? substr($name, 0, -1) : $name;
            if (!$this->validEntryName($partName)) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an invalid entry name.');
            }
            if ($isDirectory && !$this->allowedDirectoryEntry($name)) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an unknown directory entry.');
            }
            if (isset($names[$name]) || isset($folded[strtolower($name)])) {
                throw new ProcessorRejection('The DOCX ZIP directory contains a duplicate entry name.');
            }
            if (
                !is_int($size)
                || !is_int($compressedSize)
                || !is_int($compressionMethod)
                || $size < 0
                || $compressedSize < 0
            ) {
                throw new ProcessorRejection('The DOCX ZIP directory contains invalid entry metadata.');
            }
            if (!in_array($compressionMethod, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new ProcessorRejection('The DOCX ZIP directory contains unsupported compression.');
            }
            if ($encryptionMethod !== ZipArchive::EM_NONE) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an encrypted entry.');
            }
            if (!$hasExternalAttributes) {
                throw new ProcessorRejection('The DOCX ZIP directory is missing entry attributes.');
            }
            $allowedUnixFileTypes = $isDirectory ? [0, 0040000] : [0, 0100000];
            if (!in_array($unixFileType, $allowedUnixFileTypes, true)) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an unsupported Unix entry type.');
            }
            if ($compressedSize === 0 && $size !== 0) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an inconsistent compressed size.');
            }
            if ($compressedSize > 0 && $size / $compressedSize > 200) {
                throw new ProcessorRejection('The DOCX ZIP directory contains an excessive expansion ratio.');
            }

            if ($isDirectory) {
                if ($size !== 0) {
                    throw new ProcessorRejection('The DOCX ZIP directory contains a non-empty directory entry.');
                }
                $folded[strtolower($name)] = true;

                continue;
            }

            $expandedBytes += $size;
            if ($expandedBytes > ProcessorConfiguration::MAX_EXPANDED_BYTES) {
                throw new ProcessorRejection('The DOCX package exceeds its expanded-byte limit.');
            }

            if (!$this->allowedPart($name)) {
                throw ProcessorRejection::withDiagnosticContext(
                    $this->unsupportedPartMessage($name),
                    $name,
                    str_starts_with($name, 'word/embeddings/') ? 'embedded_file' : 'invalid_document',
                );
            }

            $content = $archive->getFromIndex($index, $size + 1, ZipArchive::FL_UNCHANGED);
            if (!is_string($content) || strlen($content) !== $size) {
                throw new ProcessorRejection('The DOCX package entry could not be read exactly.');
            }

            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels') || str_ends_with($name, '.vml')) {
                $this->validateXml($content);
            }

            if ($name === '[Content_Types].xml') {
                $contentTypes = $content;
            }

            if ($name === 'word/document.xml') {
                $this->validateMainDocument($content);
            }

            if (str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                $this->rejectActiveFields($content);
            }

            if (str_ends_with($name, '.rels')) {
                $relationshipDocuments[$name] = $content;
            }

            if (str_starts_with($name, 'word/media/')) {
                $mediaCount++;
                $mediaBytes += $size;
                if ($mediaCount > self::MAX_MEDIA_COUNT) {
                    throw new ProcessorRejection('The DOCX package exceeds its media-count limit.');
                }
                if ($size > 16 * 1024 * 1024) {
                    throw new ProcessorRejection('The DOCX package contains an oversized media part.');
                }
                if ($mediaBytes > self::MAX_MEDIA_BYTES) {
                    throw new ProcessorRejection('The DOCX package exceeds its aggregate media-byte limit.');
                }
                $mediaPixels += $this->validateMedia($name, $content);
                if ($mediaPixels > self::MAX_AGGREGATE_MEDIA_PIXELS) {
                    throw new ProcessorRejection('The DOCX package exceeds its aggregate media-pixel limit.');
                }
            }

            if (str_starts_with($name, 'word/fonts/')) {
                $embeddedFontCount++;
                $embeddedFontBytes += $size;
                if ($embeddedFontCount > self::MAX_EMBEDDED_FONT_COUNT) {
                    throw new ProcessorRejection('The DOCX package exceeds its embedded-font-count limit.');
                }
                if ($size > self::MAX_SINGLE_EMBEDDED_FONT_BYTES) {
                    throw new ProcessorRejection('The DOCX package contains an oversized embedded font.');
                }
                if ($embeddedFontBytes > self::MAX_EMBEDDED_FONT_BYTES) {
                    throw new ProcessorRejection('The DOCX package exceeds its aggregate embedded-font-byte limit.');
                }
            }

            if (str_starts_with($name, 'customXml/')) {
                $customXmlCount++;
                $customXmlBytes += $size;
                if ($customXmlCount > self::MAX_CUSTOM_XML_COUNT) {
                    throw new ProcessorRejection('The DOCX package exceeds its custom-XML-part-count limit.');
                }
                if ($size > self::MAX_SINGLE_CUSTOM_XML_BYTES) {
                    throw new ProcessorRejection('The DOCX package contains an oversized custom XML part.');
                }
                if ($customXmlBytes > self::MAX_CUSTOM_XML_BYTES) {
                    throw new ProcessorRejection('The DOCX package exceeds its aggregate custom-XML-byte limit.');
                }
            }

            $names[$name] = true;
            $folded[strtolower($name)] = true;
        }

        foreach (self::REQUIRED_ENTRIES as $required) {
            if (!isset($names[$required])) {
                throw new ProcessorRejection('The DOCX package is missing a required part.');
            }
        }

        if (!is_string($contentTypes)) {
            throw new ProcessorRejection('The DOCX package is missing its content-type manifest.');
        }

        $this->validateContentTypes($contentTypes, $names);

        foreach ($relationshipDocuments as $relationshipPart => $relationshipDocument) {
            [$relationships, $externalLinks, $internalTargets] = $this->inspectRelationships(
                $relationshipPart,
                $relationshipDocument,
                $names,
            );
            $relationshipCount += $relationships;
            $externalHyperlinkCount += $externalLinks;
            $sourcePart = $this->relationshipSourcePart($relationshipPart);
            if (!is_string($sourcePart) || isset($relationshipTargetsBySource[$sourcePart])) {
                throw new ProcessorRejection('The DOCX relationship graph has ambiguous ownership.');
            }
            $relationshipTargetsBySource[$sourcePart] = $internalTargets;
        }

        if ($relationshipCount > 4_000 || $externalHyperlinkCount > 1_000) {
            throw new ProcessorRejection('The DOCX relationship graph exceeds its limits.');
        }

        $this->ensureReachableParts($names, $relationshipTargetsBySource);

        return new PackageFacts(
            $archive->numFiles,
            $expandedBytes,
            $relationshipCount,
            $mediaCount,
            $externalHyperlinkCount,
        );
    }

    private function validateZipBoundary(string $bytes): int
    {
        $length = strlen($bytes);
        $minimumOffset = max(0, $length - 65_557);
        $entryCount = null;
        for ($offset = $length - 22; $offset >= $minimumOffset; $offset--) {
            if (substr($bytes, $offset, 4) !== "PK\x05\x06") {
                continue;
            }

            $footer = unpack(
                'vdisk/vcentralDisk/ventriesDisk/ventries/VcentralSize/VcentralOffset/vcommentLength',
                substr($bytes, $offset + 4, 18),
            );
            if (
                !is_array($footer)
                || !is_int($footer['disk'])
                || !is_int($footer['centralDisk'])
                || !is_int($footer['entriesDisk'])
                || !is_int($footer['entries'])
                || !is_int($footer['centralSize'])
                || !is_int($footer['centralOffset'])
                || !is_int($footer['commentLength'])
                || $footer['disk'] !== 0
                || $footer['centralDisk'] !== 0
                || $footer['entriesDisk'] !== $footer['entries']
                || $footer['entries'] < 1
                || $footer['entries'] > ProcessorConfiguration::MAX_ENTRIES
                || $footer['centralSize'] > self::MAX_CENTRAL_DIRECTORY_BYTES
                || $footer['centralOffset'] + $footer['centralSize'] !== $offset
                || $offset + 22 + $footer['commentLength'] !== $length
            ) {
                continue;
            }

            if ($entryCount !== null) {
                throw new ProcessorRejection('The DOCX ZIP container boundary is invalid or ambiguous.');
            }
            $entryCount = $footer['entries'];
        }

        if ($entryCount === null) {
            throw new ProcessorRejection('The DOCX ZIP container boundary is invalid or ambiguous.');
        }

        return $entryCount;
    }

    private function validEntryName(string $name): bool
    {
        return strlen($name) <= 512
            && preg_match('/\A[A-Za-z0-9_\.\-\[\]\/]+\z/', $name) === 1
            && !str_starts_with($name, '/')
            && !str_ends_with($name, '/')
            && !str_contains($name, '//')
            && !in_array('.', explode('/', $name), true)
            && !in_array('..', explode('/', $name), true)
            && count(explode('/', $name)) <= 16;
    }

    private function allowedPart(string $name): bool
    {
        if (in_array($name, ['[Content_Types].xml', '_rels/.rels'], true)) {
            return true;
        }

        if (preg_match('/\AdocProps\/(?:app|core|custom)\.xml\z/D', $name) === 1) {
            return true;
        }

        if (preg_match('/\AdocProps\/thumbnail\.(?:png|jpe?g|emf|wmf)\z/iD', $name) === 1) {
            return true;
        }

        if (preg_match('/\AcustomXml\/(?:item|itemProps)[1-9][0-9]*\.xml\z/D', $name) === 1) {
            return true;
        }

        if (
            preg_match('/\A(?:word|customXml|docProps)\/(?:[A-Za-z0-9_.-]+\/)*_rels\/[A-Za-z0-9_.-]+\.rels\z/D', $name) === 1
            && !$this->hasProhibitedPartSegment($name)
        ) {
            return true;
        }

        if (
            preg_match('/\Aword\/(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\.(?:xml|vml)\z/D', $name) === 1
            && !$this->hasProhibitedPartSegment($name)
        ) {
            return true;
        }

        if (preg_match('/\Aword\/printerSettings\/[A-Za-z0-9_.-]+\.bin\z/D', $name) === 1) {
            return true;
        }

        if (preg_match('/\Aword\/fonts\/[A-Za-z0-9_.-]+\.odttf\z/D', $name) === 1) {
            return true;
        }

        return preg_match('/\Aword\/media\/[A-Za-z0-9_.-]+\.(?:png|jpe?g|emf)\z/i', $name) === 1;
    }

    private function allowedDirectoryEntry(string $name): bool
    {
        if (in_array($name, ['_rels/', 'customXml/', 'customXml/_rels/', 'docProps/', 'word/'], true)) {
            return true;
        }

        return preg_match('/\Aword\/(?:[A-Za-z0-9_.-]+\/)+\z/D', $name) === 1
            && !$this->hasProhibitedPartSegment($name);
    }

    private function hasProhibitedPartSegment(string $name): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, 'word/activex/')
            || str_starts_with($lower, 'word/embeddings/')
            || str_starts_with($lower, 'customui/')
            || str_contains($lower, 'vbaproject');
    }

    private function unsupportedPartMessage(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'word/media/') => 'The DOCX package contains an unsupported media format.',
            in_array($name, ['word/commentsExtensible.xml', 'word/commentsUserData.xml'], true) => 'The DOCX package contains unsupported modern comment metadata.',
            str_starts_with($name, 'docProps/thumbnail.') => 'The DOCX package contains an unsupported package thumbnail.',
            str_starts_with($name, 'word/charts/') => 'The DOCX package contains an unsupported chart part.',
            str_starts_with($name, 'word/diagrams/') => 'The DOCX package contains an unsupported diagram part.',
            str_starts_with($name, 'word/drawings/') => 'The DOCX package contains an unsupported drawing part.',
            str_starts_with($name, 'word/glossary/') => 'The DOCX package contains an unsupported glossary part.',
            str_starts_with($name, 'word/printerSettings/') => 'The DOCX package contains unsupported printer settings.',
            str_starts_with($name, 'customXml/') => 'The DOCX package contains an unsupported custom XML part.',
            str_starts_with($name, 'word/embeddings/'),
            str_starts_with($name, 'word/activeX/'),
            str_starts_with($name, 'customUI/'),
            str_contains(strtolower($name), 'vbaproject') => 'The DOCX package contains a prohibited active or embedded part.',
            str_starts_with($name, 'word/') => 'The DOCX package contains an unsupported Word part.',
            str_starts_with($name, 'docProps/') => 'The DOCX package contains an unsupported document-properties part.',
            default => 'The DOCX package contains a prohibited or unknown part.',
        };
    }

    private function validateMedia(string $name, string $bytes): int
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'emf') {
            $this->validateEmf($bytes);

            return 0;
        }

        [$width, $height] = match ($extension) {
            'png' => $this->pngDimensions($bytes),
            'jpg', 'jpeg' => $this->jpegDimensions($bytes),
            default => throw new ProcessorRejection('The DOCX package contains an unsupported media format.'),
        };

        if (
            $width < 1
            || $height < 1
            || $width > self::MAX_MEDIA_DIMENSION
            || $height > self::MAX_MEDIA_DIMENSION
            || $width * $height > self::MAX_MEDIA_PIXELS
        ) {
            throw new ProcessorRejection('The DOCX package contains media outside the supported dimensions.');
        }

        return $width * $height;
    }

    private function validateEmf(string $bytes): void
    {
        $length = strlen($bytes);
        if (
            $length < 116
            || $this->emfDword($bytes, 0) !== 1
            || ($headerSize = $this->emfDword($bytes, 4)) < 88
            || $headerSize % 4 !== 0
            || $headerSize > $length - 20
            || $this->emfDword($bytes, 40) !== 0x464D4520
            || $this->emfDword($bytes, 44) !== 0x00010000
            || $this->emfDword($bytes, 48) !== $length
            || ($declaredRecords = $this->emfDword($bytes, 52)) < 3
            || $declaredRecords > self::MAX_EMF_RECORDS
        ) {
            throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
        }

        $handles = $this->emfWord($bytes, 56);
        $reserved = $this->emfWord($bytes, 58);
        $descriptionCharacters = $this->emfDword($bytes, 60);
        $descriptionOffset = $this->emfDword($bytes, 64);
        if (
            $handles < 1
            || $handles > self::MAX_EMF_HANDLES
            || $reserved !== 0
            || $descriptionCharacters > 4_096
            || ($descriptionCharacters === 0 && $descriptionOffset !== 0)
            || (
                $descriptionCharacters > 0
                && (
                    $descriptionOffset < 88
                    || $descriptionOffset > $headerSize
                    || $descriptionCharacters * 2 > $headerSize - $descriptionOffset
                )
            )
        ) {
            throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
        }

        $offset = 0;
        $records = 0;
        $eofSeen = false;
        while ($offset < $length) {
            if ($length - $offset < 8) {
                throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
            }

            $type = $this->emfDword($bytes, $offset);
            $size = $this->emfDword($bytes, $offset + 4);
            if (
                $size < 8
                || $size % 4 !== 0
                || $size > $length - $offset
                || $records >= self::MAX_EMF_RECORDS
            ) {
                throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
            }

            $records++;
            if ($offset === 0) {
                if ($type !== 1 || $size !== $headerSize) {
                    throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
                }
            } elseif ($type === 1 || $type < 2 || $type > 122) {
                throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
            } elseif (in_array($type, self::PROHIBITED_EMF_RECORD_TYPES, true)) {
                throw new ProcessorRejection('The DOCX package contains a prohibited EMF escape record.');
            }

            if ($type === 14) {
                if ($size < 20 || $offset + $size !== $length) {
                    throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
                }

                $paletteEntries = $this->emfDword($bytes, $offset + 8);
                $paletteOffset = $this->emfDword($bytes, $offset + 12);
                if (
                    $this->emfDword($bytes, $offset + $size - 4) !== $size
                    || $paletteEntries > 65_536
                    || (
                        $paletteEntries > 0
                        && (
                            $paletteOffset < 16
                            || $paletteOffset > $size - 4
                            || $paletteEntries * 4 > $size - 4 - $paletteOffset
                        )
                    )
                ) {
                    throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
                }
                $eofSeen = true;
            }

            $offset += $size;
        }

        if (!$eofSeen || $records !== $declaredRecords) {
            throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
        }
    }

    private function emfDword(string $bytes, int $offset): int
    {
        $value = unpack('Vvalue', substr($bytes, $offset, 4));
        if (!is_array($value) || !is_int($value['value'])) {
            throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
        }

        return $value['value'];
    }

    private function emfWord(string $bytes, int $offset): int
    {
        $value = unpack('vvalue', substr($bytes, $offset, 2));
        if (!is_array($value) || !is_int($value['value'])) {
            throw new ProcessorRejection('The DOCX package contains invalid EMF media.');
        }

        return $value['value'];
    }

    /** @return array{int, int} */
    private function pngDimensions(string $bytes): array
    {
        if (
            strlen($bytes) < 24
            || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
            || substr($bytes, 8, 8) !== "\x00\x00\x00\rIHDR"
        ) {
            throw new ProcessorRejection('The DOCX package contains invalid PNG media.');
        }

        $width = unpack('Nvalue', substr($bytes, 16, 4));
        $height = unpack('Nvalue', substr($bytes, 20, 4));
        if (!is_array($width) || !is_array($height) || !is_int($width['value']) || !is_int($height['value'])) {
            throw new ProcessorRejection('The DOCX package contains invalid PNG media.');
        }

        return [$width['value'], $height['value']];
    }

    /** @return array{int, int} */
    private function jpegDimensions(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            throw new ProcessorRejection('The DOCX package contains invalid JPEG media.');
        }

        $offset = 2;
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 0xFF) {
                throw new ProcessorRejection('The DOCX package contains invalid JPEG media.');
            }
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            $marker = ord($bytes[$offset]);
            $offset++;
            if ($marker === 0xD9 || $marker === 0xDA) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                continue;
            }
            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('nvalue', substr($bytes, $offset, 2));
            $segmentLength = is_array($segmentLength) ? $segmentLength['value'] : null;
            if (!is_int($segmentLength) || $segmentLength < 2 || $offset + $segmentLength > $length) {
                break;
            }

            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                if ($segmentLength < 7) {
                    break;
                }
                $height = unpack('nvalue', substr($bytes, $offset + 3, 2));
                $width = unpack('nvalue', substr($bytes, $offset + 5, 2));
                if (is_array($width) && is_array($height) && is_int($width['value']) && is_int($height['value'])) {
                    return [$width['value'], $height['value']];
                }
                break;
            }

            $offset += $segmentLength;
        }

        throw new ProcessorRejection('The DOCX package contains invalid JPEG media.');
    }

    private function validateXml(string $xml): void
    {
        if (strlen($xml) > 16 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $xml) === 1) {
            throw new ProcessorRejection('The DOCX package contains prohibited XML constructs.');
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        $reader = new XMLReader();
        $nodes = 0;
        $attributes = 0;
        $textBytes = 0;
        $errors = [];
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new ProcessorRejection('The DOCX package contains malformed XML.');
            }

            while ($reader->read()) {
                $nodes++;
                $attributes += $reader->hasAttributes ? $reader->attributeCount : 0;
                if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                    $textBytes += strlen($reader->value);
                }

                if (
                    $reader->depth > 128
                    || $nodes > 1_000_000
                    || $attributes > 2_000_000
                    || $textBytes > 32 * 1024 * 1024
                    || in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)
                ) {
                    throw new ProcessorRejection('The DOCX XML structure exceeds its profile.');
                }
            }
        } finally {
            $reader->close();
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }

        if ($errors !== []) {
            throw new ProcessorRejection('The DOCX package contains malformed XML.');
        }
    }

    /**
     * @param array<string, true> $packageParts
     * @return array{int, int, list<string>}
     */
    private function inspectRelationships(string $relationshipPart, string $xml, array $packageParts): array
    {
        $sourcePart = $this->relationshipSourcePart($relationshipPart);
        if ($sourcePart === null || ($sourcePart !== '' && !isset($packageParts[$sourcePart]))) {
            throw new ProcessorRejection('The DOCX relationship document has no accepted source part.');
        }

        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ProcessorRejection('The DOCX relationship document is malformed.');
        }

        $count = 0;
        $externalLinks = 0;
        $rootSeen = false;
        $rootOfficeDocumentTargets = 0;
        $ids = [];
        $internalTargets = [];
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if (!$rootSeen) {
                if (
                    $reader->depth !== 0
                    || $reader->localName !== 'Relationships'
                    || $reader->namespaceURI !== self::RELATIONSHIPS_NAMESPACE
                ) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX relationship document has an invalid root.');
                }

                $rootSeen = true;
                continue;
            }

            if (
                $reader->depth !== 1
                || $reader->localName !== 'Relationship'
                || $reader->namespaceURI !== self::RELATIONSHIPS_NAMESPACE
            ) {
                $reader->close();
                throw new ProcessorRejection('The DOCX relationship document contains an unknown element.');
            }

            $count++;
            $id = $reader->getAttribute('Id') ?? '';
            $type = $reader->getAttribute('Type') ?? '';
            $target = $reader->getAttribute('Target') ?? '';
            $mode = $reader->getAttribute('TargetMode') ?? '';
            $relationshipKind = $this->relationshipKind($type);

            if (
                preg_match('/\ArId[A-Za-z0-9_.-]{1,120}\z/', $id) !== 1
                || isset($ids[$id])
                || $relationshipKind === null
                || !$this->hasOnlyRelationshipAttributes($reader)
            ) {
                $reader->close();
                throw new ProcessorRejection('The DOCX package contains an invalid relationship.');
            }
            $ids[$id] = true;

            if ($relationshipKind === 'attachedTemplate') {
                if (
                    $mode !== 'External'
                    || $sourcePart !== 'word/settings.xml'
                    || !$this->allowedDiscardedExternalTarget($target)
                ) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX package contains a prohibited external relationship.');
                }
            } elseif ($mode === 'External') {
                if ($relationshipKind !== 'hyperlink' || !$this->allowedExternalUri($target)) {
                    throw new ProcessorRejection('The DOCX package contains a prohibited external relationship.');
                }
                $externalLinks++;
            } else {
                $resolvedTarget = $mode === ''
                    ? $this->internalRelationshipTarget($relationshipPart, $target, $packageParts)
                    : null;
                if (
                    $resolvedTarget === null
                    || !$this->relationshipTargetMatches($sourcePart, $relationshipKind, $resolvedTarget)
                ) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX package contains an invalid relationship.');
                }
                $internalTargets[] = $resolvedTarget;
            }

            if ($sourcePart === '' && $relationshipKind === 'officeDocument') {
                $rootOfficeDocumentTargets++;
            }
        }

        $reader->close();

        if (!$rootSeen || ($relationshipPart === '_rels/.rels' && $rootOfficeDocumentTargets !== 1)) {
            throw new ProcessorRejection('The DOCX package does not bind exactly one main document.');
        }

        return [$count, $externalLinks, $internalTargets];
    }

    /**
     * @param array<string, true> $packageParts
     * @param array<string, list<string>> $targetsBySource
     */
    private function ensureReachableParts(array $packageParts, array $targetsBySource): void
    {
        $reachable = [];
        $queue = [''];

        while ($queue !== []) {
            $source = array_shift($queue);
            if (!is_string($source)) {
                throw new ProcessorRejection('The DOCX relationship graph is invalid.');
            }

            foreach ($targetsBySource[$source] ?? [] as $target) {
                if (isset($reachable[$target])) {
                    continue;
                }

                $reachable[$target] = true;
                $queue[] = $target;
            }
        }

        foreach ($targetsBySource as $source => $_targets) {
            if ($source !== '' && !isset($reachable[$source])) {
                throw new ProcessorRejection('The DOCX package contains an unreachable relationship part.');
            }
        }

        foreach (array_keys($packageParts) as $partName) {
            if ($partName === '[Content_Types].xml' || str_ends_with($partName, '.rels')) {
                continue;
            }

            if (!isset($reachable[$partName])) {
                throw new ProcessorRejection('The DOCX package contains an unreferenced part.');
            }
        }
    }

    private function relationshipKind(string $type): ?string
    {
        if (in_array($type, [
            'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties',
            'http://schemas.openxmlformats.org/officedocument/2006/relationships/metadata/core-properties',
        ], true)) {
            return 'core-properties';
        }

        if ($type === 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail') {
            return 'thumbnail';
        }

        if (in_array($type, [
            self::OFFICE_RELATIONSHIP_PREFIX . 'attachedTemplate',
            self::STRICT_OFFICE_RELATIONSHIP_PREFIX . 'attachedTemplate',
        ], true)) {
            return 'attachedTemplate';
        }

        $microsoftKind = self::MICROSOFT_RELATIONSHIP_KINDS[$type] ?? null;
        if (is_string($microsoftKind)) {
            return $microsoftKind;
        }

        foreach ([self::OFFICE_RELATIONSHIP_PREFIX, self::STRICT_OFFICE_RELATIONSHIP_PREFIX] as $prefix) {
            if (!str_starts_with($type, $prefix)) {
                continue;
            }

            $kind = substr($type, strlen($prefix));
            if ($this->allowedRelationshipKind($kind)) {
                return $kind;
            }
        }

        if (
            preg_match(
                '/\Ahttp:\/\/schemas\.microsoft\.com\/office\/(?:[0-9]{4}(?:\/[0-9]{1,2})?\/)?relationships\/(?<kind>[A-Za-z][A-Za-z0-9.-]{0,127})\z/D',
                $type,
                $matches,
            ) === 1
            && $this->allowedRelationshipKind($matches['kind'])
        ) {
            return $matches['kind'];
        }

        return null;
    }

    private function allowedRelationshipKind(string $kind): bool
    {
        if (in_array($kind, self::ALLOWED_OFFICE_RELATIONSHIP_KINDS, true)) {
            return true;
        }

        return preg_match('/\A[A-Za-z][A-Za-z0-9.-]{0,127}\z/D', $kind) === 1
            && !in_array(strtolower($kind), [
                'activexcontrol',
                'attachedtemplate',
                'attachedtoolbars',
                'control',
                'ctrlprop',
                'customui',
                'externallink',
                'externallinkpath',
                'oleobject',
                'package',
                'vbaproject',
                'webextension',
                'webextensiontaskpanes',
            ], true);
    }

    private function hasOnlyRelationshipAttributes(XMLReader $reader): bool
    {
        if (!$reader->hasAttributes) {
            return false;
        }

        $allowed = ['Id', 'Target', 'TargetMode', 'Type'];
        if (!$reader->moveToFirstAttribute()) {
            return false;
        }

        do {
            if (
                $reader->namespaceURI !== ''
                || !in_array($reader->localName, $allowed, true)
            ) {
                $reader->moveToElement();

                return false;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return true;
    }

    /** @param array<string, true> $packageParts */
    private function internalRelationshipTarget(
        string $relationshipPart,
        string $target,
        array $packageParts,
    ): ?string {
        if (
            $target === ''
            || strlen($target) > 512
            || str_contains($target, '\\')
            || str_contains($target, "\0")
            || str_contains($target, ':')
            || str_contains($target, '?')
            || str_contains($target, '#')
            || str_contains($target, '%')
            || str_starts_with($target, '/')
        ) {
            return null;
        }

        $sourcePart = $this->relationshipSourcePart($relationshipPart);
        if ($sourcePart === null) {
            return null;
        }

        $sourceDirectory = $sourcePart === '' ? '' : dirname($sourcePart);
        $resolvedSegments = $sourceDirectory === '' || $sourceDirectory === '.'
            ? []
            : explode('/', $sourceDirectory);
        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                return null;
            }
            if ($segment === '..') {
                if ($resolvedSegments === []) {
                    return null;
                }
                array_pop($resolvedSegments);

                continue;
            }
            $resolvedSegments[] = $segment;
        }
        $resolved = implode('/', $resolvedSegments);

        return $this->validEntryName($resolved) && isset($packageParts[$resolved])
            ? $resolved
            : null;
    }

    private function relationshipTargetMatches(string $sourcePart, string $kind, string $target): bool
    {
        if ($sourcePart === '') {
            return match ($kind) {
                'officeDocument' => $target === 'word/document.xml',
                'core-properties' => $target === 'docProps/core.xml',
                'custom-properties' => $target === 'docProps/custom.xml',
                'extended-properties' => $target === 'docProps/app.xml',
                'thumbnail' => preg_match('/\AdocProps\/thumbnail\.(?:png|jpe?g|emf|wmf)\z/iD', $target) === 1,
                default => false,
            };
        }

        if (preg_match('/\AcustomXml\/item(?<index>[1-9][0-9]*)\.xml\z/D', $sourcePart, $matches) === 1) {
            return $kind === 'customXmlProps'
                && $target === 'customXml/itemProps' . $matches['index'] . '.xml';
        }

        if (!str_starts_with($sourcePart, 'word/')) {
            return false;
        }

        $matchesKnownKind = match ($kind) {
            'comments' => $target === 'word/comments.xml',
            'commentsExtended' => $target === 'word/commentsExtended.xml',
            'commentsIds' => $target === 'word/commentsIds.xml',
            'customXml' => $sourcePart === 'word/document.xml'
                && preg_match('/\AcustomXml\/item[1-9][0-9]*\.xml\z/D', $target) === 1,
            'endnotes' => $target === 'word/endnotes.xml',
            'font' => $sourcePart === 'word/fontTable.xml'
                && preg_match('/\Aword\/fonts\/[A-Za-z0-9_.-]+\.odttf\z/D', $target) === 1,
            'fontTable' => $target === 'word/fontTable.xml',
            'footer' => preg_match('/\Aword\/footer[1-9][0-9]*\.xml\z/D', $target) === 1,
            'footnotes' => $target === 'word/footnotes.xml',
            'header' => preg_match('/\Aword\/header[1-9][0-9]*\.xml\z/D', $target) === 1,
            'image' => preg_match('/\Aword\/media\/[A-Za-z0-9_.-]+\.(?:png|jpe?g|emf)\z/iD', $target) === 1,
            'numbering' => $target === 'word/numbering.xml',
            'person' => $target === 'word/people.xml',
            'settings' => $target === 'word/settings.xml',
            'styles' => $target === 'word/styles.xml',
            'stylesWithEffects' => $target === 'word/stylesWithEffects.xml',
            'theme' => preg_match('/\Aword\/theme\/theme[1-9][0-9]*\.xml\z/D', $target) === 1,
            'webSettings' => $target === 'word/webSettings.xml',
            default => null,
        };

        if (is_bool($matchesKnownKind)) {
            return $matchesKnownKind;
        }

        return (str_starts_with($target, 'word/') || str_starts_with($target, 'customXml/'))
            && !$this->hasProhibitedPartSegment($target);
    }

    private function relationshipSourcePart(string $relationshipPart): ?string
    {
        if ($relationshipPart === '_rels/.rels') {
            return '';
        }

        if (preg_match('/\A(?<directory>(?:[A-Za-z0-9_.-]+\/)*)_rels\/(?<file>[A-Za-z0-9_.-]+)\.rels\z/', $relationshipPart, $matches) !== 1) {
            return null;
        }

        return $matches['directory'] . $matches['file'];
    }

    private function allowedExternalUri(string $target): bool
    {
        if (strlen($target) > 2_048 || preg_match('/[\x00-\x1f\x7f]/', $target) === 1) {
            return false;
        }

        $parts = parse_url($target);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme === 'mailto') {
            return str_starts_with(strtolower($target), 'mailto:')
                && !str_starts_with(strtolower($target), 'mailto://');
        }

        return in_array($scheme, ['http', 'https'], true)
            && isset($parts['host'])
            && is_string($parts['host'])
            && $parts['host'] !== ''
            && str_starts_with(strtolower($target), $scheme . '://');
    }

    private function allowedDiscardedExternalTarget(string $target): bool
    {
        return $target !== ''
            && strlen($target) <= 2_048
            && preg_match('/[\x00-\x1f\x7f]/', $target) !== 1;
    }

    /** @param array<string, true> $packageParts */
    private function validateContentTypes(string $xml, array $packageParts): void
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ProcessorRejection('The DOCX content-type manifest is malformed.');
        }

        $rootSeen = false;
        $mainDocumentDeclarations = 0;
        $declarations = [];
        $partContentTypes = [];
        $extensionContentTypes = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if (!$rootSeen) {
                if (
                    $reader->depth !== 0
                    || $reader->localName !== 'Types'
                    || $reader->namespaceURI !== self::CONTENT_TYPES_NAMESPACE
                ) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX content-type manifest has an invalid root.');
                }

                $rootSeen = true;
                continue;
            }

            if (
                $reader->depth !== 1
                || !in_array($reader->localName, ['Default', 'Override'], true)
                || $reader->namespaceURI !== self::CONTENT_TYPES_NAMESPACE
            ) {
                $reader->close();
                throw new ProcessorRejection('The DOCX content-type manifest contains an unknown element.');
            }

            $contentType = $reader->getAttribute('ContentType') ?? '';
            $lowerContentType = strtolower($contentType);
            foreach (['macroenabled', 'vbaproject', 'activex', 'oleobject', 'embeddedpackage', 'altchunk'] as $prohibited) {
                if (str_contains($lowerContentType, $prohibited)) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX package declares a prohibited content type.');
                }
            }

            if ($reader->localName === 'Override') {
                $partName = $reader->getAttribute('PartName') ?? '';
                $key = 'part:' . strtolower($partName);
                if (
                    !str_starts_with($partName, '/')
                    || !$this->validEntryName(substr($partName, 1))
                    || !isset($packageParts[substr($partName, 1)])
                ) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX content-type manifest references an invalid part.');
                }
                $partContentTypes[substr($partName, 1)] = $contentType;
                if (
                    $partName === '/word/document.xml'
                    && $contentType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
                ) {
                    $mainDocumentDeclarations++;
                }
            } else {
                $extension = strtolower($reader->getAttribute('Extension') ?? '');
                $key = 'extension:' . $extension;
                if (preg_match('/\A[a-z0-9]{1,16}\z/', $extension) !== 1) {
                    $reader->close();
                    throw new ProcessorRejection('The DOCX content-type manifest contains an invalid extension.');
                }
                $extensionContentTypes[$extension] = $contentType;
            }

            if ($contentType === '' || strlen($contentType) > 255 || isset($declarations[$key])) {
                $reader->close();
                throw new ProcessorRejection('The DOCX content-type manifest contains an invalid declaration.');
            }
            $declarations[$key] = true;
        }

        $reader->close();

        if (!$rootSeen || $mainDocumentDeclarations !== 1) {
            throw new ProcessorRejection('The DOCX package does not declare the supported main document type.');
        }

        foreach (array_keys($packageParts) as $partName) {
            if ($partName === '[Content_Types].xml') {
                continue;
            }

            $extension = strtolower(pathinfo($partName, PATHINFO_EXTENSION));
            $declared = $partContentTypes[$partName] ?? $extensionContentTypes[$extension] ?? null;
            if (!is_string($declared) || !$this->contentTypeMatches($partName, $declared)) {
                throw new ProcessorRejection('The DOCX package declares an incompatible part content type.');
            }
        }
    }

    private function contentTypeMatches(string $partName, string $contentType): bool
    {
        if (
            $contentType === ''
            || strlen($contentType) > 255
            || preg_match('/[\x00-\x20\x7f]/', $contentType) === 1
        ) {
            return false;
        }

        $lowerContentType = strtolower($contentType);
        foreach (['activex', 'altchunk', 'embeddedpackage', 'macroenabled', 'oleobject', 'vbaproject', 'webextension'] as $prohibited) {
            if (str_contains($lowerContentType, $prohibited)) {
                return false;
            }
        }

        if (str_ends_with($partName, '.rels')) {
            return $contentType === 'application/vnd.openxmlformats-package.relationships+xml';
        }

        if (str_starts_with($partName, 'word/media/')) {
            $extension = strtolower(pathinfo($partName, PATHINFO_EXTENSION));

            return match ($extension) {
                'png' => $contentType === 'image/png',
                'jpg', 'jpeg' => $contentType === 'image/jpeg',
                'emf' => in_array($contentType, ['image/emf', 'image/x-emf'], true),
                default => false,
            };
        }

        if (str_starts_with($partName, 'word/fonts/')) {
            return preg_match('/\Aword\/fonts\/[A-Za-z0-9_.-]+\.odttf\z/D', $partName) === 1
                && $contentType === 'application/vnd.openxmlformats-officedocument.obfuscatedFont';
        }

        if (preg_match('/\AcustomXml\/item[1-9][0-9]*\.xml\z/D', $partName) === 1) {
            return $contentType === 'application/xml';
        }
        if (preg_match('/\AcustomXml\/itemProps[1-9][0-9]*\.xml\z/D', $partName) === 1) {
            return $contentType === 'application/vnd.openxmlformats-officedocument.customXmlProperties+xml';
        }

        if (preg_match('/\AdocProps\/thumbnail\.(?:png|jpe?g|emf|wmf)\z/iD', $partName) === 1) {
            return match (strtolower(pathinfo($partName, PATHINFO_EXTENSION))) {
                'png' => $contentType === 'image/png',
                'jpg', 'jpeg' => $contentType === 'image/jpeg',
                'emf' => in_array($contentType, ['image/emf', 'image/x-emf'], true),
                'wmf' => in_array($contentType, ['image/wmf', 'image/x-wmf', 'application/x-wmf'], true),
                default => false,
            };
        }

        if (preg_match('/\Aword\/printerSettings\/[A-Za-z0-9_.-]+\.bin\z/D', $partName) === 1) {
            return $contentType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.printerSettings';
        }

        $expected = match (true) {
            $partName === 'docProps/app.xml' => 'application/vnd.openxmlformats-officedocument.extended-properties+xml',
            $partName === 'docProps/core.xml' => 'application/vnd.openxmlformats-package.core-properties+xml',
            $partName === 'docProps/custom.xml' => 'application/vnd.openxmlformats-officedocument.custom-properties+xml',
            $partName === 'word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            $partName === 'word/styles.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
            $partName === 'word/stylesWithEffects.xml' => 'application/vnd.ms-word.stylesWithEffects+xml',
            $partName === 'word/settings.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
            $partName === 'word/webSettings.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.webSettings+xml',
            $partName === 'word/fontTable.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml',
            $partName === 'word/numbering.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
            $partName === 'word/footnotes.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml',
            $partName === 'word/endnotes.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.endnotes+xml',
            $partName === 'word/comments.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml',
            $partName === 'word/commentsExtended.xml' => 'application/vnd.ms-word.commentsExt+xml',
            $partName === 'word/commentsIds.xml' => 'application/vnd.ms-word.commentsIds+xml',
            $partName === 'word/people.xml' => 'application/vnd.ms-word.people+xml',
            preg_match('/\Aword\/header[1-9][0-9]*\.xml\z/D', $partName) === 1 => 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml',
            preg_match('/\Aword\/footer[1-9][0-9]*\.xml\z/D', $partName) === 1 => 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml',
            preg_match('/\Aword\/theme\/theme[1-9][0-9]*\.xml\z/D', $partName) === 1 => 'application/vnd.openxmlformats-officedocument.theme+xml',
            default => null,
        };

        if ($expected !== null) {
            return $contentType === $expected;
        }

        if (str_ends_with($partName, '.vml')) {
            return $contentType === 'application/vnd.openxmlformats-officedocument.vmlDrawing';
        }

        return str_starts_with($partName, 'word/')
            && str_ends_with($partName, '.xml')
            && preg_match(
                '/\Aapplication\/vnd\.(?:openxmlformats-officedocument|ms-(?:office|word))\.[A-Za-z0-9.+-]+\+xml\z/iD',
                $contentType,
            ) === 1;
    }

    private function validateMainDocument(string $xml): void
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ProcessorRejection('The DOCX main document is malformed.');
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $valid = $reader->depth === 0
                && $reader->localName === 'document'
                && in_array($reader->namespaceURI, self::WORDPROCESSING_NAMESPACES, true);
            $reader->close();

            if (!$valid) {
                throw new ProcessorRejection('The DOCX main document has an invalid root.');
            }

            return;
        }

        $reader->close();
        throw new ProcessorRejection('The DOCX main document is empty.');
    }

    private function rejectActiveFields(string $xml): void
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ProcessorRejection('The DOCX WordprocessingML part is malformed.');
        }

        $instructions = '';
        while ($reader->read()) {
            if (
                $reader->nodeType !== XMLReader::ELEMENT
                || !in_array($reader->namespaceURI, self::WORDPROCESSING_NAMESPACES, true)
            ) {
                continue;
            }

            if ($reader->localName === 'altChunk') {
                $reader->close();
                throw new ProcessorRejection('The DOCX package contains prohibited alternate content.');
            }

            if ($reader->localName === 'instrText') {
                $instructions .= $reader->readString();
            } elseif ($reader->localName === 'fldSimple') {
                foreach (self::WORDPROCESSING_NAMESPACES as $namespace) {
                    $instruction = $reader->getAttributeNs('instr', $namespace);
                    if (is_string($instruction)) {
                        $instructions .= $instruction;
                    }
                }
            }

            if (strlen($instructions) > 1_048_576) {
                $reader->close();
                throw new ProcessorRejection('The DOCX field instructions exceed their limit.');
            }
        }

        $reader->close();
        $normalized = preg_replace('/[\x00-\x20]+/', '', $instructions);
        if (
            !is_string($normalized)
            || preg_match('/(?:DDE(?:AUTO)?|INCLUDETEXT|INCLUDEPICTURE|DATABASE|LINK)/i', $normalized) === 1
        ) {
            throw new ProcessorRejection('The DOCX package contains a prohibited active field.');
        }
    }
}

final readonly class DocxConversionSanitizer
{
    private const string CONTENT_TYPES_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const string RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const array WORDPROCESSING_NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];

    private const array OFFICE_RELATIONSHIP_PREFIXES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/',
        'http://purl.oclc.org/ooxml/officeDocument/relationships/',
    ];

    public function stripForConversion(string $docx): string
    {
        $sourcePath = tempnam('/tmp', 'artifactflow-docx-font-source-');
        $outputPath = tempnam('/tmp', 'artifactflow-docx-font-output-');
        if (!is_string($sourcePath) || !is_string($outputPath)) {
            if (is_string($sourcePath) && is_file($sourcePath)) {
                unlink($sourcePath);
            }
            if (is_string($outputPath) && is_file($outputPath)) {
                unlink($outputPath);
            }

            throw new ProcessorUnavailable('DOCX conversion-copy staging failed.');
        }

        try {
            if (file_put_contents($sourcePath, $docx, LOCK_EX) !== strlen($docx) || !chmod($sourcePath, 0600)) {
                throw new ProcessorUnavailable('DOCX conversion-copy staging failed.');
            }

            $source = new ZipArchive();
            if ($source->open($sourcePath, ZipArchive::CHECKCONS) !== true) {
                throw new ProcessorRejection('The DOCX conversion source could not be reopened safely.');
            }

            try {
                $stripEmbeddedFonts = $this->containsEmbeddedFont($source);
                $stripCustomXml = $this->containsCustomXml($source);
                $stripConversionMetadata = $this->containsConversionMetadata($source);
                $stripAttachedTemplate = $this->containsAttachedTemplate($source);
                if (!$stripEmbeddedFonts && !$stripCustomXml && !$stripConversionMetadata && !$stripAttachedTemplate) {
                    return $docx;
                }

                $output = new ZipArchive();
                if ($output->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new ProcessorUnavailable('DOCX conversion copy could not be created.');
                }

                try {
                    $this->copySanitized(
                        $source,
                        $output,
                        $stripEmbeddedFonts,
                        $stripCustomXml,
                        $stripConversionMetadata,
                        $stripAttachedTemplate,
                    );
                } finally {
                    if (!$output->close()) {
                        throw new ProcessorUnavailable('DOCX conversion copy could not be finalized.');
                    }
                }
            } finally {
                $source->close();
            }

            $sanitized = file_get_contents(
                $outputPath,
                false,
                null,
                0,
                ProcessorConfiguration::MAX_INPUT_BYTES + 1,
            );
            if (
                !is_string($sanitized)
                || $sanitized === ''
                || strlen($sanitized) > ProcessorConfiguration::MAX_INPUT_BYTES
            ) {
                throw new ProcessorRejection('The DOCX conversion copy exceeds its byte limit.');
            }

            return $sanitized;
        } finally {
            if (is_file($sourcePath)) {
                unlink($sourcePath);
            }
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    private function containsEmbeddedFont(ZipArchive $archive): bool
    {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index, ZipArchive::FL_UNCHANGED);
            if (is_string($name) && preg_match('/\Aword\/fonts\/[A-Za-z0-9_.-]+\.odttf\z/D', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsCustomXml(ZipArchive $archive): bool
    {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index, ZipArchive::FL_UNCHANGED);
            if (is_string($name) && str_starts_with($name, 'customXml/')) {
                return true;
            }
        }

        return false;
    }

    private function containsConversionMetadata(ZipArchive $archive): bool
    {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index, ZipArchive::FL_UNCHANGED);
            if (
                is_string($name)
                && (
                    str_starts_with($name, 'word/printerSettings/')
                    || preg_match('/\AdocProps\/thumbnail\.(?:png|jpe?g|emf|wmf)\z/iD', $name) === 1
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function containsAttachedTemplate(ZipArchive $archive): bool
    {
        $index = $archive->locateName('word/_rels/settings.xml.rels', ZipArchive::FL_UNCHANGED);
        if ($index === false) {
            return false;
        }

        $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
        if (!is_array($stat) || !is_int($stat['size'] ?? null)) {
            throw new ProcessorRejection('The DOCX conversion source contains invalid entry metadata.');
        }
        $xml = $archive->getFromIndex($index, $stat['size'] + 1, ZipArchive::FL_UNCHANGED);
        if (!is_string($xml) || strlen($xml) !== $stat['size']) {
            throw new ProcessorRejection('The DOCX conversion source entry could not be read exactly.');
        }

        $document = $this->loadXml($xml, 'settings relationships');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', self::RELATIONSHIPS_NAMESPACE);
        foreach (self::OFFICE_RELATIONSHIP_PREFIXES as $prefix) {
            $nodes = $xpath->query(
                '/r:Relationships/r:Relationship[@Type="' . $prefix . 'attachedTemplate" and @TargetMode="External"]',
            );
            if ($nodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy relationships could not be queried.');
            }
            if ($nodes->length > 0) {
                return true;
            }
        }

        return false;
    }

    private function copySanitized(
        ZipArchive $source,
        ZipArchive $output,
        bool $stripEmbeddedFonts,
        bool $stripCustomXml,
        bool $stripConversionMetadata,
        bool $stripAttachedTemplate,
    ): void {
        for ($index = 0; $index < $source->numFiles; $index++) {
            $stat = $source->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat) || !is_string($stat['name'] ?? null) || !is_int($stat['size'] ?? null)) {
                throw new ProcessorRejection('The DOCX conversion source contains invalid entry metadata.');
            }

            $name = $stat['name'];
            if ($stripEmbeddedFonts && (
                $name === 'word/fonts/'
                || preg_match('/\Aword\/fonts\/[A-Za-z0-9_.-]+\.odttf\z/D', $name) === 1
            )) {
                continue;
            }
            if ($stripCustomXml && ($name === 'customXml/' || str_starts_with($name, 'customXml/'))) {
                continue;
            }
            if (
                $stripConversionMetadata
                && (
                    $name === 'word/printerSettings/'
                    || str_starts_with($name, 'word/printerSettings/')
                    || preg_match('/\AdocProps\/thumbnail\.(?:png|jpe?g|emf|wmf)\z/iD', $name) === 1
                )
            ) {
                continue;
            }

            if (str_ends_with($name, '/')) {
                if (!$output->addEmptyDir(substr($name, 0, -1))) {
                    throw new ProcessorUnavailable('DOCX conversion-copy directory could not be written.');
                }

                continue;
            }

            $bytes = $source->getFromIndex($index, $stat['size'] + 1, ZipArchive::FL_UNCHANGED);
            if (!is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new ProcessorRejection('The DOCX conversion source entry could not be read exactly.');
            }

            if ($name === '[Content_Types].xml') {
                $bytes = $this->stripContentTypes(
                    $bytes,
                    $stripEmbeddedFonts,
                    $stripCustomXml,
                    $stripConversionMetadata,
                );
            } elseif (str_ends_with($name, '.rels')) {
                $bytes = $this->stripRelationships(
                    $bytes,
                    $stripEmbeddedFonts,
                    $stripCustomXml,
                    $stripConversionMetadata,
                    $stripAttachedTemplate,
                );
            } elseif ($stripEmbeddedFonts && $name === 'word/fontTable.xml') {
                $bytes = $this->stripFontReferences($bytes);
            } elseif ($stripAttachedTemplate && $name === 'word/settings.xml') {
                $bytes = $this->stripAttachedTemplateReferences($bytes);
            }
            if ($stripCustomXml && str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                $bytes = $this->stripCustomXmlBindings($bytes);
            }

            if (!$output->addFromString($name, $bytes)) {
                throw new ProcessorUnavailable('DOCX conversion-copy entry could not be written.');
            }
        }
    }

    private function stripContentTypes(
        string $xml,
        bool $stripEmbeddedFonts,
        bool $stripCustomXml,
        bool $stripConversionMetadata,
    ): string {
        $document = $this->loadXml($xml, 'content-type manifest');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ct', self::CONTENT_TYPES_NAMESPACE);
        $queries = [];
        if ($stripEmbeddedFonts) {
            $queries[] = '/ct:Types/*[@ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"]';
        }
        if ($stripCustomXml) {
            $queries[] = '/ct:Types/ct:Override[starts-with(@PartName, "/customXml/")]';
        }
        if ($stripConversionMetadata) {
            $queries[] = '/ct:Types/ct:Override[starts-with(@PartName, "/word/printerSettings/")]';
            $queries[] = '/ct:Types/ct:Override[starts-with(@PartName, "/docProps/thumbnail.")]';
        }
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy content types could not be queried.');
            }
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $this->saveXml($document);
    }

    private function stripRelationships(
        string $xml,
        bool $stripEmbeddedFonts,
        bool $stripCustomXml,
        bool $stripConversionMetadata,
        bool $stripAttachedTemplate,
    ): string {
        $document = $this->loadXml($xml, 'relationships');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', self::RELATIONSHIPS_NAMESPACE);

        $kinds = [];
        if ($stripEmbeddedFonts) {
            $kinds[] = 'font';
        }
        if ($stripCustomXml) {
            $kinds[] = 'customXml';
            $kinds[] = 'customXmlProps';
        }
        if ($stripConversionMetadata) {
            $kinds[] = 'printerSettings';
        }
        if ($stripAttachedTemplate) {
            $kinds[] = 'attachedTemplate';
        }
        foreach (self::OFFICE_RELATIONSHIP_PREFIXES as $prefix) {
            foreach ($kinds as $kind) {
                $nodes = $xpath->query('/r:Relationships/r:Relationship[@Type="' . $prefix . $kind . '"]');
                if ($nodes === false) {
                    throw new ProcessorUnavailable('DOCX conversion-copy relationships could not be queried.');
                }
                foreach ($nodes as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        if ($stripConversionMetadata) {
            $nodes = $xpath->query(
                '/r:Relationships/r:Relationship[@Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail"]',
            );
            if ($nodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy relationships could not be queried.');
            }
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $this->saveXml($document);
    }

    private function stripAttachedTemplateReferences(string $xml): string
    {
        $document = $this->loadXml($xml, 'settings');
        $xpath = new DOMXPath($document);

        foreach (self::WORDPROCESSING_NAMESPACES as $index => $namespace) {
            $prefix = 'w' . $index;
            $xpath->registerNamespace($prefix, $namespace);
            $nodes = $xpath->query('//' . $prefix . ':attachedTemplate');
            if ($nodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy template references could not be queried.');
            }
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $this->saveXml($document);
    }

    private function stripCustomXmlBindings(string $xml): string
    {
        $document = $this->loadXml($xml, 'WordprocessingML part');
        $xpath = new DOMXPath($document);

        foreach (self::WORDPROCESSING_NAMESPACES as $index => $namespace) {
            $prefix = 'c' . $index;
            $xpath->registerNamespace($prefix, $namespace);
            $bindingNodes = $xpath->query('//' . $prefix . ':dataBinding');
            if ($bindingNodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy data bindings could not be queried.');
            }
            foreach ($bindingNodes as $node) {
                $node->parentNode?->removeChild($node);
            }

            $customXmlNodes = $xpath->query('//' . $prefix . ':customXml');
            if ($customXmlNodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy custom XML wrappers could not be queried.');
            }
            foreach ($customXmlNodes as $node) {
                $parent = $node->parentNode;
                if ($parent === null) {
                    continue;
                }
                while ($node->firstChild !== null) {
                    $child = $node->firstChild;
                    if (
                        $child instanceof DOMElement
                        && $child->namespaceURI === $namespace
                        && $child->localName === 'customXmlPr'
                    ) {
                        $node->removeChild($child);

                        continue;
                    }
                    $parent->insertBefore($child, $node);
                }
                $parent->removeChild($node);
            }
        }

        return $this->saveXml($document);
    }

    private function stripFontReferences(string $xml): string
    {
        $document = $this->loadXml($xml, 'font table');
        $xpath = new DOMXPath($document);

        foreach (self::WORDPROCESSING_NAMESPACES as $index => $namespace) {
            $prefix = 'w' . $index;
            $xpath->registerNamespace($prefix, $namespace);
            $nodes = $xpath->query(
                sprintf(
                    '//%1$s:embedRegular | //%1$s:embedBold | //%1$s:embedItalic | //%1$s:embedBoldItalic',
                    $prefix,
                ),
            );
            if ($nodes === false) {
                throw new ProcessorUnavailable('DOCX conversion-copy font references could not be queried.');
            }
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $this->saveXml($document);
    }

    private function loadXml(string $xml, string $part): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)) {
            throw new ProcessorRejection('The DOCX conversion-copy ' . $part . ' is malformed.');
        }

        return $document;
    }

    private function saveXml(DOMDocument $document): string
    {
        if (!$document->documentElement instanceof DOMElement) {
            throw new ProcessorRejection('The DOCX conversion-copy XML is empty.');
        }

        $xml = $document->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new ProcessorUnavailable('DOCX conversion-copy XML could not be serialized.');
        }

        return $xml;
    }
}

final readonly class ConversionResult
{
    public function __construct(public string $pdfBytes)
    {
    }
}

final readonly class LibreOfficeConverter
{
    public const string ENGINE = 'libreoffice';

    public const string ENGINE_VERSION = '26.2.5';

    private const int TIMEOUT_SECONDS = 30;

    public function convert(string $docx): ConversionResult
    {
        $root = '/tmp/artifactflow-docx-' . bin2hex(random_bytes(12));
        $inputDirectory = $root . '/input';
        $outputDirectory = $root . '/output';
        $profileDirectory = $root . '/profile';

        foreach ([$root, $inputDirectory, $outputDirectory, $profileDirectory] as $directory) {
            if (!mkdir($directory, 0700)) {
                $this->discardTree($root);
                throw new ProcessorUnavailable('DOCX conversion workspace could not be created.');
            }
        }

        $inputPath = $inputDirectory . '/document.docx';
        try {
            if (file_put_contents($inputPath, $docx, LOCK_EX) !== strlen($docx) || !chmod($inputPath, 0600)) {
                throw new ProcessorUnavailable('DOCX conversion input could not be staged.');
            }

            $filter = 'pdf:writer_pdf_Export:{"ExportBookmarks":{"type":"boolean","value":"true"},"ExportLinksRelativeFsys":{"type":"boolean","value":"false"},"UseTaggedPDF":{"type":"boolean","value":"true"}}';
            $profileUrl = 'file://' . $profileDirectory;
            $this->run([
                '/usr/bin/setsid',
                '/opt/libreoffice26.2/program/soffice',
                '--headless',
                '--nologo',
                '--nodefault',
                '--nolockcheck',
                '--norestore',
                '--invisible',
                '-env:UserInstallation=' . $profileUrl,
                '--convert-to',
                $filter,
                '--outdir',
                $outputDirectory,
                $inputPath,
            ]);

            $outputPath = $outputDirectory . '/document.pdf';
            $pdf = is_file($outputPath)
                ? file_get_contents($outputPath, false, null, 0, ProcessorConfiguration::MAX_OUTPUT_BYTES + 1)
                : false;

            if (
                !is_string($pdf)
                || $pdf === ''
                || strlen($pdf) > ProcessorConfiguration::MAX_OUTPUT_BYTES
                || !str_starts_with($pdf, '%PDF-')
                || preg_match('/%%EOF\s*\z/D', $pdf) !== 1
            ) {
                throw new ProcessorRejection('DOCX conversion did not produce a bounded complete PDF.');
            }

            return new ConversionResult($pdf);
        } finally {
            $this->discardTree($root);
        }
    }

    public function verifyHealth(): void
    {
        $output = $this->run(['/opt/libreoffice26.2/program/soffice', '--headless', '--version']);
        if (!str_contains($output, self::ENGINE_VERSION)) {
            throw new ProcessorUnavailable('DOCX converter version is unexpected.');
        }
    }

    /** @param non-empty-list<string> $command */
    private function run(array $command): string
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'HOME' => '/tmp',
                'LANG' => 'C.UTF-8',
                'LC_ALL' => 'C.UTF-8',
                'PATH' => '/usr/bin:/bin:/opt/libreoffice26.2/program',
                'SAL_DISABLE_OPENCL' => '1',
            ],
            ['bypass_shell' => true, 'suppress_errors' => true],
        );

        if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
            throw new ProcessorUnavailable('DOCX converter process could not be started.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $pid = null;
        $exitCode = -1;

        try {
            while (true) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (strlen($stdout) > 16_384 || strlen($stderr) > 16_384) {
                    throw new ProcessorUnavailable('DOCX converter output exceeded its diagnostic limit.');
                }

                $status = proc_get_status($process);
                $pid = is_int($status['pid'] ?? null) ? $status['pid'] : $pid;
                if (($status['running'] ?? false) !== true) {
                    $exitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : -1;
                    break;
                }

                if (microtime(true) >= $deadline) {
                    if (is_int($pid)) {
                        posix_kill(-$pid, SIGKILL);
                    }
                    throw new ProcessorUnavailable('DOCX conversion exceeded its wall-clock deadline.');
                }

                usleep(10_000);
            }
        } catch (Throwable $exception) {
            if (is_int($pid)) {
                posix_kill(-$pid, SIGKILL);
            } else {
                proc_terminate($process, SIGKILL);
            }

            throw $exception;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode < 0 && $closed >= 0) {
                $exitCode = $closed;
            }
        }

        if ($exitCode !== 0) {
            throw new ProcessorRejection('DOCX conversion failed.');
        }

        return $stdout;
    }

    private function discardTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $entries = scandir($root);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->discardTree($path);
            } else {
                unlink($path);
            }
        }

        rmdir($root);
    }
}

final readonly class ProcessorResult
{
    public const string RESPONSE_SCHEMA = 'docx-processor-response-v1';

    public function __construct(
        public string $pdfBytes,
        public PackageFacts $facts,
    ) {
    }

    /** @return array<string, string> */
    public function headers(ProcessorRequest $request, string $secret): array
    {
        $outputHash = hash('sha256', $this->pdfBytes);
        $values = [
            $request->nonce,
            (string) strlen($request->bytes),
            $request->inputSha256,
            'application/pdf',
            (string) strlen($this->pdfBytes),
            $outputHash,
            self::RESPONSE_SCHEMA,
            ProcessorRequest::PROFILE,
            LibreOfficeConverter::ENGINE,
            LibreOfficeConverter::ENGINE_VERSION,
            (string) $this->facts->entryCount,
            (string) $this->facts->expandedBytes,
            (string) $this->facts->relationshipCount,
            (string) $this->facts->mediaCount,
            (string) $this->facts->externalHyperlinkCount,
        ];

        return [
            'Content-Type' => 'application/pdf',
            'X-ArtifactFlow-Processor-Nonce' => $request->nonce,
            'X-ArtifactFlow-Input-Bytes' => (string) strlen($request->bytes),
            'X-ArtifactFlow-Input-SHA256' => $request->inputSha256,
            'X-ArtifactFlow-Response-SHA256' => $outputHash,
            'X-ArtifactFlow-Processor-Schema' => self::RESPONSE_SCHEMA,
            'X-ArtifactFlow-Processor-Profile' => ProcessorRequest::PROFILE,
            'X-ArtifactFlow-Processor-Engine' => LibreOfficeConverter::ENGINE,
            'X-ArtifactFlow-Processor-Engine-Version' => LibreOfficeConverter::ENGINE_VERSION,
            'X-ArtifactFlow-Package-Entry-Count' => (string) $this->facts->entryCount,
            'X-ArtifactFlow-Package-Expanded-Bytes' => (string) $this->facts->expandedBytes,
            'X-ArtifactFlow-Package-Relationship-Count' => (string) $this->facts->relationshipCount,
            'X-ArtifactFlow-Package-Media-Count' => (string) $this->facts->mediaCount,
            'X-ArtifactFlow-Package-External-Hyperlink-Count' => (string) $this->facts->externalHyperlinkCount,
            'X-ArtifactFlow-Processor-Signature' => hash_hmac(
                'sha256',
                implode("\n", ['artifactflow-docx-processor-response-v1', ...$values]),
                $secret,
            ),
        ];
    }
}
