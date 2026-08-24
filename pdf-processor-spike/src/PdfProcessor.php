<?php

declare(strict_types=1);

namespace ArtifactFlow\PdfProcessor;

use JsonException;
use RuntimeException;

class ProcessorRejection extends RuntimeException
{
}

class ProcessorAuthenticationFailure extends RuntimeException
{
}

final class ProcessorClockSkewFailure extends ProcessorAuthenticationFailure
{
}

final class EngineProtocolFailure extends RuntimeException
{
}

final class EngineRejection extends RuntimeException
{
    private const array ALLOWED_REASONS = [
        'active_content',
        'encrypted',
        'input_size',
        'interactive_form',
        'invalid_eof',
        'invalid_header',
        'invalid_pdf',
        'object_limit',
        'page_limit',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('PDF engine rejected the document.');
    }

    public static function fromStderr(string $stderr): self
    {
        $matches = [];

        if (preg_match('/\Arejected: ([a-z_]{1,40})\r?\n?\z/D', $stderr, $matches) !== 1) {
            return new self('invalid_pdf');
        }

        $reason = $matches[1];

        return new self(in_array($reason, self::ALLOWED_REASONS, true) ? $reason : 'invalid_pdf');
    }
}

final class EngineUnavailable extends RuntimeException
{
}

final readonly class ProcessorConfiguration
{
    public const int MAX_INPUT_BYTES = 16 * 1024 * 1024;

    public const int MAX_PAGES = 250;

    public const int MAX_TEXT_BYTES = 8 * 1024 * 1024;

    public const int MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    public function __construct(
        public string $sharedSecret,
        public int $maxClockSkewSeconds = 120,
    ) {
        if (strlen($this->sharedSecret) < 32) {
            throw new RuntimeException('PDF processor authentication is not configured.');
        }

        if ($this->maxClockSkewSeconds < 1 || $this->maxClockSkewSeconds > 300) {
            throw new RuntimeException('PDF processor clock-skew configuration is invalid.');
        }
    }

    public static function fromEnvironment(): self
    {
        $sharedSecret = self::normalizedSecret(self::environmentString('PDF_PROCESSOR_SHARED_SECRET'));

        if ($sharedSecret === null) {
            throw new RuntimeException('PDF processor authentication is not configured.');
        }

        return new self(
            sharedSecret: $sharedSecret,
            maxClockSkewSeconds: min(
                300,
                self::positiveEnvironmentInteger('PDF_PROCESSOR_MAX_CLOCK_SKEW_SECONDS', 120),
            ),
        );
    }

    private static function environmentString(string $key): string
    {
        $value = getenv($key);

        return is_string($value) ? trim($value) : '';
    }

    private static function positiveEnvironmentInteger(string $key, int $default): int
    {
        $configured = self::environmentString($key);

        if ($configured === '') {
            return $default;
        }

        if (!ctype_digit($configured)) {
            throw new RuntimeException('PDF processor limit configuration is invalid.');
        }

        $normalized = ltrim($configured, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new RuntimeException('PDF processor limit configuration is invalid.');
        }

        $value = (int) $normalized;

        if ($value < 1) {
            throw new RuntimeException('PDF processor limit configuration is invalid.');
        }

        return $value;
    }

    private static function normalizedSecret(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        if (!str_starts_with($secret, 'base64:')) {
            return $secret;
        }

        $decoded = base64_decode(substr($secret, 7), true);

        return $decoded === false ? null : $decoded;
    }
}

final readonly class ProcessorRequest
{
    public function __construct(
        public string $nonce,
        public string $inputSha256,
        public string $bytes,
    ) {
    }

    /**
     * @param array<string, string> $server
     */
    public static function authenticated(
        ProcessorConfiguration $configuration,
        array $server,
        string $bytes,
        ?int $currentTime = null,
    ): self {
        $timestamp = self::serverValue($server, 'HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP');
        $nonce = self::serverValue($server, 'HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE');
        $signature = self::serverValue($server, 'HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE');
        $contentType = strtolower(trim((string) strtok(self::serverValue($server, 'CONTENT_TYPE'), ';')));
        $contentLength = self::serverValue($server, 'CONTENT_LENGTH');
        $timestampSeconds = filter_var(
            $timestamp,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $contentLengthBytes = self::canonicalPositiveInteger($contentLength);

        if (
            !is_int($timestampSeconds)
            || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
        ) {
            throw new ProcessorAuthenticationFailure('Unauthenticated PDF processor request.');
        }

        if (
            $contentType !== 'application/pdf'
            || $contentLengthBytes === null
            || $contentLengthBytes > ProcessorConfiguration::MAX_INPUT_BYTES
            || $bytes === ''
            || strlen($bytes) > ProcessorConfiguration::MAX_INPUT_BYTES
        ) {
            throw new ProcessorRejection('Invalid PDF processor request envelope.');
        }

        $inputSha256 = hash('sha256', $bytes);
        $expected = hash_hmac('sha256', implode("\n", [
            'artifactflow-pdf-processor-request-v1',
            $timestamp,
            $nonce,
            $contentType,
            $contentLength,
            $inputSha256,
        ]), $configuration->sharedSecret);

        if (!hash_equals($expected, $signature)) {
            throw new ProcessorAuthenticationFailure('Unauthenticated PDF processor request.');
        }

        if ($contentLengthBytes !== strlen($bytes)) {
            throw new ProcessorRejection('Invalid PDF processor request body.');
        }

        if (abs(($currentTime ?? time()) - $timestampSeconds) > $configuration->maxClockSkewSeconds) {
            throw new ProcessorClockSkewFailure('Authenticated PDF processor request is outside the clock-skew window.');
        }

        return new self(
            nonce: $nonce,
            inputSha256: $inputSha256,
            bytes: $bytes,
        );
    }

    public static function fromGlobals(ProcessorConfiguration $configuration): self
    {
        /** @var array<string, string> $server */
        $server = array_filter(
            $_SERVER,
            static fn (mixed $value): bool => is_string($value),
        );
        $bytes = file_get_contents(
            'php://input',
            false,
            null,
            0,
            ProcessorConfiguration::MAX_INPUT_BYTES + 1,
        );

        if (!is_string($bytes)) {
            throw new ProcessorRejection('Invalid PDF processor request body.');
        }

        return self::authenticated($configuration, $server, $bytes);
    }

    /**
     * @param array<string, string> $server
     */
    private static function serverValue(array $server, string $key): string
    {
        return $server[$key] ?? '';
    }

    private static function canonicalPositiveInteger(string $value): ?int
    {
        if (!ctype_digit($value) || $value[0] === '0') {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $value;
    }
}

final readonly class EngineInspection
{
    public function __construct(
        public int $pageCount,
        public string $pdfVersion,
        public bool $truncated,
        public string $text,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new EngineProtocolFailure('PDF engine returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new EngineProtocolFailure('PDF engine response has an invalid shape.');
        }

        $keys = array_keys($decoded);
        sort($keys);

        if ($keys !== ['pages', 'pdf_version', 'text', 'truncated']) {
            throw new EngineProtocolFailure('PDF engine response has unexpected fields.');
        }

        $pages = $decoded['pages'] ?? null;
        $pdfVersion = $decoded['pdf_version'] ?? null;
        $truncated = $decoded['truncated'] ?? null;
        $text = $decoded['text'] ?? null;

        if (
            !is_int($pages)
            || $pages < 1
            || $pages > ProcessorConfiguration::MAX_PAGES
            || !is_string($pdfVersion)
            || preg_match('/^(?:1\.[0-7]|2\.0)$/D', $pdfVersion) !== 1
            || !is_bool($truncated)
            || !is_string($text)
            || strlen($text) > ProcessorConfiguration::MAX_TEXT_BYTES
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1
        ) {
            throw new EngineProtocolFailure('PDF engine response contains invalid values.');
        }

        return new self(
            pageCount: $pages,
            pdfVersion: $pdfVersion,
            truncated: $truncated,
            text: $text,
        );
    }

    public function extractionState(): string
    {
        if ($this->truncated) {
            return 'partially_indexed';
        }

        return trim($this->text) === '' ? 'no_embedded_text' : 'indexed';
    }
}

final class EngineProcessLease
{
    /** @param resource $handle */
    private function __construct(
        private $handle,
    ) {
    }

    public static function acquire(string $path, int $timeoutSeconds): self
    {
        $handle = fopen($path, 'c');

        if (!is_resource($handle) || !chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new EngineUnavailable('PDF engine admission could not be initialized.');
        }

        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new self($handle);
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        throw new EngineUnavailable('PDF engine admission remained busy beyond its deadline.');
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}

final readonly class PdfBoxEngine
{
    private const int MAX_STDERR_BYTES = 4096;

    /**
     * @param non-empty-list<string> $command
     */
    public function __construct(
        private array $command,
        private int $timeoutSeconds,
        private string $temporaryDirectory = '/tmp',
        private string $engineLockPath = '/tmp/artifactflow-pdf-engine.lock',
    ) {
        if (
            $this->timeoutSeconds < 1
            || $this->timeoutSeconds > 60
            || $this->engineLockPath === ''
        ) {
            throw new RuntimeException('PDF engine timeout configuration is invalid.');
        }
    }

    public static function production(): self
    {
        return new self(
            command: [
                '/usr/bin/java',
                '-Xms32m',
                '-Xmx384m',
                '-XX:MaxMetaspaceSize=96m',
                '-cp',
                '/opt/pdfbox-app.jar:/srv/pdf-processor-spike/classes',
                'app.artifactflow.pdfspike.Main',
            ],
            timeoutSeconds: 12,
        );
    }

    public function inspect(string $bytes): EngineInspection
    {
        $path = tempnam($this->temporaryDirectory, 'artifactflow-pdf-');

        if (!is_string($path)) {
            throw new EngineUnavailable('PDF engine input could not be allocated.');
        }

        try {
            if (!chmod($path, 0600)) {
                throw new EngineUnavailable('PDF engine input permissions could not be restricted.');
            }

            $written = file_put_contents($path, $bytes, LOCK_EX);

            if ($written !== strlen($bytes)) {
                throw new EngineUnavailable('PDF engine input could not be written.');
            }

            return EngineInspection::fromJson($this->run([...$this->command, 'inspect', $path]));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function verifyHealth(): void
    {
        $output = trim($this->run([...$this->command, 'self-test']));

        if ($output !== 'pdf-processor-spike self-test passed') {
            throw new EngineUnavailable('PDF engine health response is invalid.');
        }
    }

    /**
     * @param non-empty-list<string> $command
     */
    private function run(array $command): string
    {
        $lease = EngineProcessLease::acquire($this->engineLockPath, $this->timeoutSeconds);

        try {
            return $this->runWithLease($command);
        } finally {
            $lease->release();
        }
    }

    /**
     * @param non-empty-list<string> $command
     */
    private function runWithLease(array $command): string
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            [
                'HOME' => '/tmp',
                'LANG' => 'C.UTF-8',
                'LC_ALL' => 'C.UTF-8',
                'PATH' => '/usr/bin:/bin',
            ],
            ['bypass_shell' => true, 'suppress_errors' => true],
        );

        if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
            throw new EngineUnavailable('PDF engine process could not be started.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $stderrBytes = 0;
        $deadline = microtime(true) + $this->timeoutSeconds;
        $exitCode = -1;
        $timedOut = false;
        $outputExceeded = false;

        try {
            while (true) {
                $stdoutChunk = stream_get_contents($pipes[1]);
                $stderrChunk = stream_get_contents($pipes[2]);

                if (is_string($stdoutChunk)) {
                    $stdout .= $stdoutChunk;
                }

                if (is_string($stderrChunk)) {
                    $stderr .= $stderrChunk;
                    $stderrBytes += strlen($stderrChunk);
                }

                if (
                    strlen($stdout) > ProcessorConfiguration::MAX_RESPONSE_BYTES
                    || $stderrBytes > self::MAX_STDERR_BYTES
                ) {
                    $outputExceeded = true;
                    proc_terminate($process, 9);
                    break;
                }

                $status = proc_get_status($process);

                if ($status['running'] !== true) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process, 9);
                    break;
                }

                usleep(10_000);
            }

            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);

            if (is_string($stdoutChunk)) {
                $stdout .= $stdoutChunk;
            }

            if (is_string($stderrChunk)) {
                $stderr .= $stderrChunk;
                $stderrBytes += strlen($stderrChunk);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExitCode = proc_close($process);

            if ($exitCode < 0 && $closedExitCode >= 0) {
                $exitCode = $closedExitCode;
            }
        }

        if ($timedOut) {
            throw new EngineUnavailable('PDF engine exceeded its wall-clock deadline.');
        }

        if (
            $outputExceeded
            || strlen($stdout) > ProcessorConfiguration::MAX_RESPONSE_BYTES
            || $stderrBytes > self::MAX_STDERR_BYTES
        ) {
            throw new EngineProtocolFailure('PDF engine output exceeded its byte limit.');
        }

        if ($exitCode === 65) {
            throw EngineRejection::fromStderr($stderr);
        }

        if ($exitCode !== 0) {
            throw new EngineUnavailable('PDF engine processing failed.');
        }

        return $stdout;
    }
}

final readonly class ProcessorResult
{
    private const string PROFILE = 'pdfbox-3.0.8-native-text-v1';

    public function __construct(
        public int $pageCount,
        public string $pdfVersion,
        public string $extractionState,
        public string $processorProfile,
        public string $text,
    ) {
    }

    public static function fromInspection(EngineInspection $inspection): self
    {
        return new self(
            pageCount: $inspection->pageCount,
            pdfVersion: $inspection->pdfVersion,
            extractionState: $inspection->extractionState(),
            processorProfile: self::PROFILE,
            text: $inspection->text,
        );
    }

    public function toJson(): string
    {
        try {
            $json = json_encode([
                'page_count' => $this->pageCount,
                'pdf_version' => $this->pdfVersion,
                'extraction_state' => $this->extractionState,
                'processor_profile' => $this->processorProfile,
                'text' => $this->text,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new EngineProtocolFailure('PDF processor response encoding failed.', previous: $exception);
        }

        if (strlen($json) > ProcessorConfiguration::MAX_RESPONSE_BYTES) {
            throw new EngineProtocolFailure('PDF processor response exceeds its byte limit.');
        }

        return $json;
    }

    public function signature(ProcessorRequest $request, string $sharedSecret): string
    {
        return hash_hmac('sha256', implode("\n", [
            'artifactflow-pdf-processor-response-v1',
            $request->nonce,
            $request->inputSha256,
            hash('sha256', $this->toJson()),
        ]), $sharedSecret);
    }
}
