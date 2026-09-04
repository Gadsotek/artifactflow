<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\CreateDocxTool;
use App\Mcp\Tools\CreateXlsxTool;
use App\Mcp\Tools\ReplaceDocxTool;
use App\Mcp\Tools\ReplaceXlsxTool;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Serializer;
use Tests\TestCase;

final class OfficeArtifactToolSchemaTest extends TestCase
{
    public function test_office_creation_tools_publish_complete_strict_input_schemas(): void
    {
        $xlsx = $this->serializedSchema(app(CreateXlsxTool::class));
        $docx = $this->serializedSchema(app(CreateDocxTool::class));

        $this->assertSame(
            [
                'workspace_uid',
                'title',
                'xlsx_base64',
                'change_summary',
                'description',
                'status',
                'category_uid',
                'category_name',
                'parent_page_uid',
                'tags',
                'provenance',
            ],
            array_keys($xlsx['properties']),
        );
        $this->assertSame(
            ['workspace_uid', 'title', 'xlsx_base64', 'change_summary'],
            $xlsx['required'],
        );
        $this->assertSame(['draft', 'approved', 'deprecated', 'archived'], data_get($xlsx, 'properties.status.enum'));
        $this->assertSame('string', data_get($xlsx, 'properties.tags.items.type'));
        $this->assertSame('object', data_get($xlsx, 'properties.provenance.type'));

        $this->assertSame(
            [
                'workspace_uid',
                'title',
                'docx_base64',
                'change_summary',
                'description',
                'status',
                'category_uid',
                'category_name',
                'parent_page_uid',
                'tags',
                'provenance',
            ],
            array_keys($docx['properties']),
        );
        $this->assertSame(
            ['workspace_uid', 'title', 'docx_base64', 'change_summary'],
            $docx['required'],
        );
        $this->assertSame('string', data_get($docx, 'properties.docx_base64.type'));
        $this->assertSame('object', data_get($docx, 'properties.provenance.type'));
    }

    public function test_office_replacement_tools_require_version_concurrency_and_upload_bytes(): void
    {
        $xlsx = $this->serializedSchema(app(ReplaceXlsxTool::class));
        $docx = $this->serializedSchema(app(ReplaceDocxTool::class));

        $this->assertSame(
            ['page_uid', 'base_version_uid', 'xlsx_base64', 'change_summary', 'provenance'],
            array_keys($xlsx['properties']),
        );
        $this->assertSame(
            ['page_uid', 'base_version_uid', 'xlsx_base64', 'change_summary'],
            $xlsx['required'],
        );
        $this->assertSame(
            ['page_uid', 'base_version_uid', 'docx_base64', 'change_summary', 'provenance'],
            array_keys($docx['properties']),
        );
        $this->assertSame(
            ['page_uid', 'base_version_uid', 'docx_base64', 'change_summary'],
            $docx['required'],
        );
    }

    /**
     * @return array{type: string, properties: array<string, mixed>, required: list<string>}
     */
    private function serializedSchema(
        CreateDocxTool|CreateXlsxTool|ReplaceDocxTool|ReplaceXlsxTool $tool,
    ): array {
        $factory = new JsonSchemaTypeFactory();
        $properties = $tool->schema($factory);
        $serialized = Serializer::serialize(JsonSchema::object($properties));
        $this->assertIsArray($serialized['properties'] ?? null);
        $this->assertIsArray($serialized['required'] ?? null);

        /** @var array{type: string, properties: array<string, mixed>, required: list<string>} $serialized */
        return $serialized;
    }
}
