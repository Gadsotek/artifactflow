<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Mcp;

use App\Application\Mcp\McpCreateCategoryTool;
use App\Application\Mcp\McpCreateExternalShareTool;
use App\Application\Mcp\McpCreateImageTool;
use App\Application\Mcp\McpCreatePdfTool;
use App\Application\Mcp\McpCreateTagTool;
use App\Application\Mcp\McpCreateTool;
use App\Application\Mcp\McpListTaxonomyTool;
use App\Application\Mcp\McpOrganizeTool;
use App\Application\Mcp\McpReadTool;
use App\Application\Mcp\McpReplaceImageTool;
use App\Application\Mcp\McpReplacePdfTool;
use App\Application\Mcp\McpRevertTool;
use App\Application\Mcp\McpSearchTool;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolError;
use App\Application\Mcp\McpToolResult;
use App\Application\Mcp\McpUpdateDescriptionTool;
use App\Application\Mcp\McpUpdateTool;
use App\Application\Mcp\McpWirePayload;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class McpToolInputArchitectureTest extends TestCase
{
    /**
     * @param class-string $handler
     */
    #[DataProvider('handlersWithInput')]
    public function test_application_handlers_do_not_accept_transport_argument_bags(string $handler): void
    {
        $method = new ReflectionMethod($handler, 'handle');

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $this->assertNotContains(
                $type->getName(),
                [McpToolArguments::class, Request::class, 'array'],
                sprintf('%s::handle() must accept a tool-specific DTO, not a transport or array boundary.', $handler),
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function handlersWithInput(): iterable
    {
        yield 'create category' => [McpCreateCategoryTool::class];
        yield 'create external share' => [McpCreateExternalShareTool::class];
        yield 'create image' => [McpCreateImageTool::class];
        yield 'create PDF' => [McpCreatePdfTool::class];
        yield 'create tag' => [McpCreateTagTool::class];
        yield 'create page' => [McpCreateTool::class];
        yield 'list taxonomy' => [McpListTaxonomyTool::class];
        yield 'organize' => [McpOrganizeTool::class];
        yield 'read' => [McpReadTool::class];
        yield 'replace image' => [McpReplaceImageTool::class];
        yield 'replace PDF' => [McpReplacePdfTool::class];
        yield 'revert' => [McpRevertTool::class];
        yield 'search' => [McpSearchTool::class];
        yield 'update description' => [McpUpdateDescriptionTool::class];
        yield 'update content' => [McpUpdateTool::class];
    }

    public function test_tool_results_accept_only_typed_wire_payloads(): void
    {
        $method = new ReflectionMethod(McpToolResult::class, 'success');
        $type = $method->getParameters()[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(McpWirePayload::class, $type->getName());

        $method = new ReflectionMethod(McpToolResult::class, 'error');
        $type = $method->getParameters()[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(McpToolError::class, $type->getName());
    }
}
