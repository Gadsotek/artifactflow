<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpVersionIngestPayload implements McpWirePayload
{
    public function __construct(
        public string $uid,
        public string $pageVersionUid,
        public int $versionNumber,
        public string $contentHash,
        public string $operation,
        public string $ingestMethod,
        public string $actorUserUid,
        public McpUntrustedText $actorName,
        public ?string $mcpAccessTokenUid,
        public ?string $mcpTransportSessionId,
        public ?McpUntrustedText $mcpReportedClientName,
        public ?McpUntrustedText $mcpReportedClientVersion,
        public bool $provenanceSuppliedAtIngest,
        public ?string $derivedFromVersionUid,
        public ?string $contentEquivalentToVersionUid,
        public string $contentOriginVersionUid,
        public string $recordedAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'uid' => $this->uid,
            'page_version_uid' => $this->pageVersionUid,
            'version_number' => $this->versionNumber,
            'content_hash' => $this->contentHash,
            'operation' => $this->operation,
            'ingest_method' => $this->ingestMethod,
            'actor_user_uid' => $this->actorUserUid,
            'actor_name' => $this->actorName->toWire(),
        ];

        $client = [
            'mcp_access_token_uid' => $this->mcpAccessTokenUid,
            'mcp_transport_session_id' => $this->mcpTransportSessionId,
            'mcp_reported_client_name' => $this->mcpReportedClientName?->toWire(),
            'mcp_reported_client_version' => $this->mcpReportedClientVersion?->toWire(),
        ];

        foreach ($client as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        $payload['provenance_supplied_at_ingest'] = $this->provenanceSuppliedAtIngest;
        $lineage = [
            'derived_from_version_uid' => $this->derivedFromVersionUid,
            'content_equivalent_to_version_uid' => $this->contentEquivalentToVersionUid,
        ];

        foreach ($lineage as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        $payload['content_origin_version_uid'] = $this->contentOriginVersionUid;
        $payload['recorded_at'] = $this->recordedAt;

        return $payload;
    }
}
