<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use Illuminate\Http\JsonResponse;

/**
 * JSON-RPC 2.0 envelope codec for the MCP endpoint, including the MCP
 * tools/call content-envelope shape. Keeps transport framing out of the
 * controller and the tool workflows.
 */
final class McpJsonRpc
{
    /**
     * @param array<string, mixed> $result
     */
    public function result(mixed $id, array $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    public function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toolSuccess(mixed $id, array $payload): JsonResponse
    {
        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]],
            'isError' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $error
     */
    public function toolError(mixed $id, array $error): JsonResponse
    {
        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['error' => $error], JSON_THROW_ON_ERROR),
            ]],
            'isError' => true,
        ]);
    }

    public function notFound(mixed $id): JsonResponse
    {
        return $this->toolError($id, [
            'type' => 'not_found',
            'message' => 'Page not found.',
        ]);
    }
}
