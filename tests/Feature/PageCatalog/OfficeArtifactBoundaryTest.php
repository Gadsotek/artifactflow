<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\DocxProcessorConfiguration;
use App\Application\PageCatalog\PageContentStrategyRegistry;
use App\Application\PageCatalog\XlsxProcessorConfiguration;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Http\Requests\PageCatalog\DocxUploadRules;
use App\Http\Requests\PageCatalog\XlsxUploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Mockery;
use Tests\TestCase;

final class OfficeArtifactBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pages.artifact_max_bytes' => 16,
            'pages.max_html_bytes' => 16,
            'pages.max_markdown_bytes' => 16,
            'docx_processor.enabled' => true,
            'docx_processor.url' => 'http://docx-processor.test',
            'docx_processor.socket_path' => null,
            'docx_processor.shared_secret' => 'test-docx-boundary-secret-0000000001',
            'docx_processor.connect_timeout_seconds' => 2,
            'docx_processor.timeout_seconds' => 35,
            'pdf_processor.enabled' => true,
            'xlsx_processor.enabled' => true,
            'xlsx_processor.url' => 'http://xlsx-processor.test',
            'xlsx_processor.socket_path' => null,
            'xlsx_processor.shared_secret' => 'test-xlsx-boundary-secret-0000000001',
            'xlsx_processor.connect_timeout_seconds' => 2,
            'xlsx_processor.timeout_seconds' => 15,
        ]);
    }

    public function test_xlsx_and_docx_upload_rules_reject_missing_wrong_unreadable_and_oversized_files(): void
    {
        $xlsx = app(XlsxUploadRules::class);
        $docx = app(DocxUploadRules::class);

        $this->assertSame(1, $xlsx->maxUploadKilobytes());
        $this->assertSame(1, $docx->maxUploadKilobytes());

        $validator = Validator::make([], []);
        $this->assertNull($xlsx->validateUpload($validator, 'xlsx_file', null));
        $this->assertSame('Choose an Excel workbook to upload.', $validator->errors()->first('xlsx_file'));
        $validator = Validator::make([], []);
        $this->assertNull($docx->validateUpload($validator, 'docx_file', null));
        $this->assertSame('Choose a Word document to upload.', $validator->errors()->first('docx_file'));

        $validator = Validator::make([], []);
        $this->assertNull($xlsx->validateUpload(
            $validator,
            'xlsx_file',
            UploadedFile::fake()->createWithContent('workbook.xls', 'small'),
        ));
        $this->assertSame('Excel workbook uploads must use a .xlsx file.', $validator->errors()->first('xlsx_file'));
        $validator = Validator::make([], []);
        $this->assertNull($docx->validateUpload(
            $validator,
            'docx_file',
            UploadedFile::fake()->createWithContent('document.doc', 'small'),
        ));
        $this->assertSame('Word document uploads must use a .docx file.', $validator->errors()->first('docx_file'));

        $validator = Validator::make([], []);
        $this->assertNull($xlsx->validateUpload(
            $validator,
            'xlsx_file',
            UploadedFile::fake()->createWithContent('workbook.xlsx', str_repeat('x', 17)),
        ));
        $this->assertSame('XLSX exceeds the configured size limit.', $validator->errors()->first('xlsx_file'));
        $validator = Validator::make([], []);
        $this->assertNull($docx->validateUpload(
            $validator,
            'docx_file',
            UploadedFile::fake()->createWithContent('document.docx', str_repeat('x', 17)),
        ));
        $this->assertSame('DOCX exceeds the configured size limit.', $validator->errors()->first('docx_file'));

        foreach ([
            [XlsxUploadRules::class, 'xlsx_file', 'xlsx', 'The Excel workbook upload could not be read.'],
            [DocxUploadRules::class, 'docx_file', 'docx', 'The Word document upload could not be read.'],
        ] as [$ruleClass, $field, $extension, $message]) {
            $file = Mockery::mock(UploadedFile::class);
            $file->shouldReceive('isValid')->once()->andReturnTrue();
            $file->shouldReceive('getClientOriginalExtension')->once()->andReturn($extension);
            $file->shouldReceive('getSize')->once()->andReturn(4);
            $file->shouldReceive('getContent')->once()->andThrow(new \RuntimeException('Unreadable fixture.'));
            $this->assertInstanceOf(UploadedFile::class, $file);
            $validator = Validator::make([], []);
            $rules = app($ruleClass);
            $this->assertNull($rules->validateUpload($validator, $field, $file));
            $this->assertSame($message, $validator->errors()->first($field));
        }

        foreach ([
            [XlsxUploadRules::class, 'xlsx_file', 'xlsx', 'XLSX exceeds the configured size limit.'],
            [DocxUploadRules::class, 'docx_file', 'docx', 'DOCX exceeds the configured size limit.'],
        ] as [$ruleClass, $field, $extension, $message]) {
            $file = Mockery::mock(UploadedFile::class);
            $file->shouldReceive('isValid')->once()->andReturnTrue();
            $file->shouldReceive('getClientOriginalExtension')->once()->andReturn($extension);
            $file->shouldReceive('getSize')->once()->andReturnFalse();
            $file->shouldReceive('getContent')->once()->andReturn(str_repeat('x', 17));
            $this->assertInstanceOf(UploadedFile::class, $file);
            $validator = Validator::make([], []);
            $rules = app($ruleClass);
            $this->assertNull($rules->validateUpload($validator, $field, $file));
            $this->assertSame($message, $validator->errors()->first($field));
        }

        $this->assertSame(
            'small',
            $xlsx->validateUpload(
                Validator::make([], []),
                'xlsx_file',
                UploadedFile::fake()->createWithContent('WORKBOOK.XLSX', 'small'),
            ),
        );
        $this->assertSame(
            'small',
            $docx->validateUpload(
                Validator::make([], []),
                'docx_file',
                UploadedFile::fake()->createWithContent('DOCUMENT.DOCX', 'small'),
            ),
        );
    }

    public function test_office_content_strategies_enforce_type_enablement_package_size_and_filename(): void
    {
        $strategies = app(PageContentStrategyRegistry::class);
        $xlsx = $strategies->for(PageType::Xlsx);
        $docx = $strategies->for(PageType::Docx);

        $this->assertSame([PageType::Xlsx], $xlsx->supportedTypes());
        $this->assertSame([PageType::Docx], $docx->supportedTypes());
        $this->assertTrue($xlsx->requiresContentForTextProjection(PageType::Xlsx));
        $this->assertTrue($docx->requiresContentForTextProjection(PageType::Docx));
        $this->assertFalse($xlsx->supportsSearchTextReindex(PageType::Xlsx));
        $this->assertFalse($docx->supportsSearchTextReindex(PageType::Docx));
        $xlsx->validateInput(PageType::Xlsx, "PK\x03\x04small");
        $docx->validateInput(PageType::Docx, "PK\x03\x04small");
        $xlsx->validateSourceFilename(PageType::Xlsx, null);
        $xlsx->validateSourceFilename(PageType::Xlsx, 'WORKBOOK.XLSX');
        $docx->validateSourceFilename(PageType::Docx, null);
        $docx->validateSourceFilename(PageType::Docx, 'DOCUMENT.DOCX');
        $this->addToAssertionCount(6);

        foreach ([
            static fn () => $xlsx->validateInput(PageType::Docx, "PK\x03\x04small"),
            static fn () => $docx->validateInput(PageType::Xlsx, "PK\x03\x04small"),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A content strategy must reject another page type.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([
            static fn () => $xlsx->validateInput(PageType::Xlsx, ''),
            static fn () => $xlsx->validateInput(PageType::Xlsx, str_repeat('x', 17)),
            static fn () => $xlsx->validateInput(PageType::Xlsx, 'not-a-package'),
            static fn () => $xlsx->validateSourceFilename(PageType::Xlsx, 'workbook.xls'),
            static fn () => $docx->validateInput(PageType::Docx, ''),
            static fn () => $docx->validateInput(PageType::Docx, str_repeat('x', 17)),
            static fn () => $docx->validateInput(PageType::Docx, 'not-a-package'),
            static fn () => $docx->validateSourceFilename(PageType::Docx, 'document.doc'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Invalid Office content must fail closed.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }

        config(['xlsx_processor.enabled' => false]);
        $this->assertDomainRuleViolation(static fn () => $xlsx->validateInput(PageType::Xlsx, "PK\x03\x04small"));
        config(['docx_processor.enabled' => false]);
        $this->assertDomainRuleViolation(static fn () => $docx->validateInput(PageType::Docx, "PK\x03\x04small"));
    }

    public function test_office_processor_configuration_rejects_ambiguous_or_unsafe_values(): void
    {
        $xlsx = app(XlsxProcessorConfiguration::class);
        $docx = app(DocxProcessorConfiguration::class);

        $this->assertTrue($xlsx->enabled());
        $this->assertTrue($docx->enabled());
        $this->assertSame('http://xlsx-processor.test', $xlsx->origin());
        $this->assertSame('http://docx-processor.test', $docx->origin());
        $this->assertStringContainsString('xlsx-boundary', $xlsx->sharedSecret());
        $this->assertStringContainsString('docx-boundary', $docx->sharedSecret());
        $this->assertNull($xlsx->socketPath());
        $this->assertNull($docx->socketPath());
        $this->assertSame(2, $xlsx->connectTimeoutSeconds());
        $this->assertSame(15, $xlsx->timeoutSeconds());
        $this->assertSame(2, $docx->connectTimeoutSeconds());
        $this->assertSame(35, $docx->timeoutSeconds());

        foreach ([
            ['xlsx_processor.enabled', 'yes', static fn () => $xlsx->enabled()],
            ['docx_processor.enabled', 'yes', static fn () => $docx->enabled()],
            ['xlsx_processor.url', 'http://xlsx-processor.test/path', static fn () => $xlsx->origin()],
            ['docx_processor.url', 'ftp://docx-processor.test', static fn () => $docx->origin()],
            ['xlsx_processor.shared_secret', 'short', static fn () => $xlsx->sharedSecret()],
            ['docx_processor.shared_secret', 'change-me-docx-processor-secret', static fn () => $docx->sharedSecret()],
            ['xlsx_processor.socket_path', 'relative.sock', static fn () => $xlsx->socketPath()],
            ['docx_processor.socket_path', "bad\0socket", static fn () => $docx->socketPath()],
            ['xlsx_processor.connect_timeout_seconds', 0, static fn () => $xlsx->connectTimeoutSeconds()],
            ['xlsx_processor.timeout_seconds', 61, static fn () => $xlsx->timeoutSeconds()],
            ['docx_processor.connect_timeout_seconds', '2', static fn () => $docx->connectTimeoutSeconds()],
            ['docx_processor.timeout_seconds', 0, static fn () => $docx->timeoutSeconds()],
        ] as [$key, $value, $operation]) {
            config([$key => $value]);

            try {
                $operation();
                $this->fail(sprintf('Unsafe configuration [%s] must be rejected.', $key));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->setUpSafeConfiguration($key);
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertDomainRuleViolation(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The operation must reject invalid Office content.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
    }

    private function setUpSafeConfiguration(string $key): void
    {
        $safe = [
            'xlsx_processor.enabled' => true,
            'docx_processor.enabled' => true,
            'xlsx_processor.url' => 'http://xlsx-processor.test',
            'docx_processor.url' => 'http://docx-processor.test',
            'xlsx_processor.shared_secret' => 'test-xlsx-boundary-secret-0000000001',
            'docx_processor.shared_secret' => 'test-docx-boundary-secret-0000000001',
            'xlsx_processor.socket_path' => null,
            'docx_processor.socket_path' => null,
            'xlsx_processor.connect_timeout_seconds' => 2,
            'xlsx_processor.timeout_seconds' => 15,
            'docx_processor.connect_timeout_seconds' => 2,
            'docx_processor.timeout_seconds' => 35,
        ];

        config([$key => $safe[$key]]);
    }
}
