<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\PassageRetrieval\PagePassageIndex;
use Generator;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

final class PassageAwareCallTool extends CallTool
{
    public function __construct(private readonly PagePassageIndex $index)
    {
    }

    /** @return Generator<JsonRpcResponse>|JsonRpcResponse */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        if ($request->get('name') !== 'search_passages') {
            return parent::handle($request, $context);
        }

        // A client may call from a cached listing. Serialize admission with
        // disable, including the interval between SDK resolution and execution.
        return DB::transaction(function () use ($request, $context): Generator|JsonRpcResponse {
            if ($this->index->activeGeneration(lock: true) === null) {
                throw new JsonRpcException('Tool [search_passages] not found.', -32602, $request->id);
            }

            return parent::handle($request, $context);
        });
    }
}
