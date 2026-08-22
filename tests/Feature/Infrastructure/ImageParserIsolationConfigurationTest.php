<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Application\PageCatalog\ImageArtifactLimits;
use Tests\TestCase;

final class ImageParserIsolationConfigurationTest extends TestCase
{
    public function test_image_parser_is_a_private_hardened_service_without_a_network_namespace(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $parserBlock = $this->serviceBlock($compose, 'image-parser', 'app');

        $this->assertStringContainsString('target: image-parser', $parserBlock);
        $this->assertStringContainsString('read_only: true', $parserBlock);
        $this->assertStringContainsString('no-new-privileges:true', $parserBlock);
        $this->assertStringContainsString("cap_drop:\n      - ALL", $parserBlock);
        $this->assertStringContainsString('tmpfs:', $parserBlock);
        $this->assertStringContainsString('pids_limit:', $parserBlock);
        $this->assertStringContainsString('mem_limit: 512m', $parserBlock);
        $this->assertStringContainsString('cpus: 1.0', $parserBlock);
        $this->assertStringContainsString('network_mode: none', $parserBlock);
        $this->assertStringContainsString('IMAGE_PARSER_SOCKET_PATH: /run/artifactflow/image-parser/parser.sock', $parserBlock);
        $this->assertStringContainsString('image-parser-socket:/run/artifactflow/image-parser', $parserBlock);
        $this->assertStringContainsString(
            "image-parser-socket-init:\n        condition: service_completed_successfully",
            $parserBlock,
        );
        $this->assertStringNotContainsString('networks:', $parserBlock);
        $this->assertStringNotContainsString('ports:', $parserBlock);
        $this->assertStringNotContainsString('/var/www/html', $parserBlock);

        $this->assertStringNotContainsString("\n  image-parser:\n    internal: true", $this->afterNeedle($compose, "\nnetworks:"));
        $this->assertStringContainsString('chmod 0755 /socket && chown 10001:10001 /socket', $compose);
        $this->assertStringContainsString("cap_add:\n      - CHOWN\n      - FOWNER", $compose);
    }

    public function test_parser_image_uses_one_memory_bounded_normalization_process(): void
    {
        $dockerfile = $this->readProjectFile('Dockerfile');
        $parserStage = $this->afterNeedle($dockerfile, ' AS image-parser');
        $productionStage = $this->afterNeedle($dockerfile, ' AS production');

        $this->assertMatchesRegularExpression(
            '/FROM php:[^\n]+-cli-alpine[^\n]+ AS image-parser/',
            $dockerfile,
        );
        $this->assertStringContainsString('COPY image-parser', $parserStage);
        $this->assertStringContainsString('USER image-parser', $parserStage);
        $this->assertStringContainsString('gd', $parserStage);
        $this->assertStringContainsString('exif', $parserStage);
        $this->assertStringNotContainsString('webp', strtolower($parserStage));
        $this->assertStringNotContainsString('PHP_CLI_SERVER_WORKERS=', $parserStage);
        $this->assertStringContainsString('CMD ["/srv/image-parser/start.sh"]', $parserStage);
        $this->assertStringNotContainsString('COPY app ', $this->beforeNeedle($parserStage, "\nFROM "));
        $this->assertStringNotContainsString("\n    gd \\", $productionStage);
        $this->assertStringNotContainsString("\n    exif \\", $productionStage);

        $startScript = $this->readProjectFile('image-parser/start.sh');
        $this->assertStringContainsString('PHP_CLI_SERVER_WORKERS', $startScript);
        $this->assertStringContainsString('must stay at one worker', $startScript);
        $this->assertStringContainsString('unset PHP_CLI_SERVER_WORKERS', $startScript);
        $this->assertStringContainsString('${PORT:-8080}', $startScript);
        $this->assertStringContainsString('127.0.0.1:${port}', $startScript);
        $this->assertStringContainsString('UNIX-LISTEN:', $startScript);
        $this->assertStringContainsString('memory_limit=448M', $startScript);
        $this->assertStringContainsString('max_execution_time=15', $startScript);

        $healthcheck = $this->readProjectFile('image-parser/healthcheck.php');
        $this->assertStringContainsString('ParserConfiguration::fromEnvironment()', $healthcheck);
        $this->assertStringNotContainsString('->verifyHealth()', $healthcheck);
        $this->assertStringContainsString('unix://', $healthcheck);
        $this->assertStringNotContainsString(
            'imagedestroy(',
            strtolower($this->readProjectFile('image-parser/src/ImageParser.php')),
        );
    }

    public function test_new_upload_defaults_are_16_megapixels_but_the_retained_image_envelope_stays_40_megapixels(): void
    {
        $this->assertSame(16 * 1024 * 1024, ImageArtifactLimits::MAX_UPLOAD_PIXELS);
        $this->assertSame(40 * 1024 * 1024, ImageArtifactLimits::STORED_MAX_PIXELS);
        $this->assertStringContainsString(
            "'max_image_pixels' => (int) env('PAGE_IMAGE_MAX_PIXELS', 16 * 1024 * 1024)",
            $this->readProjectFile('config/pages.php'),
        );
        $this->assertStringContainsString(
            'private const int MAX_PIXELS = 16 * 1024 * 1024;',
            $this->readProjectFile('image-parser/src/ImageParser.php'),
        );
        $this->assertSame(
            1,
            substr_count(
                $this->readProjectFile('docker-compose.yml'),
                'PAGE_IMAGE_MAX_PIXELS: ${PAGE_IMAGE_MAX_PIXELS:-16777216}',
            ),
        );
        $parserBlock = $this->serviceBlock(
            $this->readProjectFile('docker-compose.yml'),
            'image-parser',
            'app',
        );
        $this->assertStringNotContainsString('PAGE_IMAGE_MAX_BYTES', $parserBlock);
        $this->assertStringNotContainsString('PAGE_IMAGE_MAX_PIXELS', $parserBlock);
        $this->assertStringNotContainsString('PAGE_IMAGE_MAX_DIMENSION', $parserBlock);

        foreach (['.env.example', '.env.production.example'] as $example) {
            $this->assertStringContainsString(
                'PAGE_IMAGE_MAX_PIXELS=16777216',
                $this->readProjectFile($example),
                $example,
            );
        }
    }

    public function test_only_the_e2e_stack_health_gates_the_real_parser_service(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');
        $testDatabase = $this->serviceBlock($compose, 'db-test', 'image-parser');
        $e2eApp = $this->serviceBlock($compose, 'e2e-app', 'e2e-artifact-host');

        $this->assertStringNotContainsString('image-parser:', $testDatabase);
        $this->assertStringContainsString("image-parser:\n        condition: service_healthy", $e2eApp);
        $this->assertStringContainsString('healthcheck:', $this->serviceBlock($compose, 'image-parser', 'app'));
        $this->assertStringContainsString('target: image-parser', $compose);
    }

    public function test_non_app_runtime_roles_do_not_receive_parser_credentials(): void
    {
        $compose = $this->readProjectFile('docker-compose.yml');

        foreach ([
            ['artifact-host', 'e2e-app'],
            ['e2e-artifact-host', 'e2e-edge'],
            ['worker', 'scheduler'],
            ['scheduler', 'reverb'],
            ['reverb', 'vite'],
        ] as [$service, $nextService]) {
            $serviceBlock = $this->serviceBlock($compose, $service, $nextService);

            $this->assertStringContainsString('IMAGE_PARSER_URL: ""', $serviceBlock, $service);
            $this->assertStringContainsString('IMAGE_PARSER_SOCKET_PATH: ""', $serviceBlock, $service);
            $this->assertStringContainsString('IMAGE_PARSER_SHARED_SECRET: ""', $serviceBlock, $service);
        }
    }

    public function test_application_code_cannot_call_native_image_parser_functions(): void
    {
        $nativeFunctions = array_values(array_unique([
            ...(get_extension_funcs('gd') ?: []),
            ...(get_extension_funcs('exif') ?: []),
        ]));
        $this->assertNotSame([], $nativeFunctions, 'The dev image must expose GD/EXIF so this gate can enumerate them.');
        $nativeFunctionLookup = array_fill_keys(array_map('strtolower', $nativeFunctions), true);
        $violations = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source);
            $tokens = token_get_all($source);

            foreach ($tokens as $index => $token) {
                if (
                    !is_array($token)
                    || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                ) {
                    continue;
                }

                $functionName = strtolower(ltrim(strrchr('\\' . $token[1], '\\') ?: $token[1], '\\'));

                if (!isset($nativeFunctionLookup[$functionName])) {
                    continue;
                }

                $previous = $this->neighboringCodeToken($tokens, $index, -1);
                $next = $this->neighboringCodeToken($tokens, $index, 1);

                if (
                    $next === '('
                    && $previous !== T_OBJECT_OPERATOR
                    && $previous !== T_NULLSAFE_OBJECT_OPERATOR
                    && $previous !== T_DOUBLE_COLON
                ) {
                    $violations[] = sprintf('%s:%d:%s', $file->getPathname(), $token[2], $functionName);
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private function neighboringCodeToken(array $tokens, int $index, int $direction): int|string|null
    {
        for ($cursor = $index + $direction; isset($tokens[$cursor]); $cursor += $direction) {
            $token = $tokens[$cursor];

            if (
                is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            return is_array($token) ? $token[0] : $token;
        }

        return null;
    }

    private function serviceBlock(string $compose, string $service, string $nextService): string
    {
        return $this->beforeNeedle(
            $this->afterNeedle($compose, sprintf("\n  %s:", $service)),
            sprintf("\n  %s:", $nextService),
        );
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }

    private function afterNeedle(string $haystack, string $needle): string
    {
        $position = strpos($haystack, $needle);
        $this->assertNotFalse($position, sprintf('Expected to find [%s].', $needle));

        return substr($haystack, $position + strlen($needle));
    }

    private function beforeNeedle(string $haystack, string $needle): string
    {
        $position = strpos($haystack, $needle);
        $this->assertNotFalse($position, sprintf('Expected to find [%s].', $needle));

        return substr($haystack, 0, $position);
    }
}
