<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Provenance\ExternalOriginReferenceView;
use App\Application\Provenance\PageVersionProvenanceView;
use App\Application\Provenance\ProducerAssertionView;
use App\Application\Provenance\ProducerClaimExtension;
use App\Application\Provenance\VersionIngestView;
use App\Application\Provenance\VersionOriginView;

final readonly class McpProvenancePayload
{
    /**
     * @return array<string, mixed>
     */
    public function make(PageVersionProvenanceView $view): array
    {
        return [
            'provenance_completeness' => $view->completeness->value,
            'strongest_evidence' => $view->strongestEvidence !== null
                ? $view->strongestEvidence->value
                : 'none',
            'version_ingest' => $view->versionIngest instanceof VersionIngestView
                ? $this->ingest($view->versionIngest)
                : null,
            'page_origin_producers' => array_map($this->producer(...), $view->pageOriginProducers),
            'direct_version_producers' => array_map($this->producer(...), $view->directVersionProducers),
            'effective_content_origin' => $view->effectiveContentOrigin instanceof VersionOriginView
                ? $this->origin($view->effectiveContentOrigin)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ingest(VersionIngestView $ingest): array
    {
        return [
            'uid' => $ingest->uid,
            'page_version_uid' => $ingest->pageVersionUid,
            'version_number' => $ingest->versionNumber,
            'content_hash' => $ingest->contentHash,
            'operation' => $ingest->operation->value,
            'ingest_method' => $ingest->ingestMethod->value,
            'actor_user_uid' => $ingest->actorUserUid,
            'actor_name' => McpDataEnvelope::text($ingest->actorName),
            'mcp_access_token_uid' => $ingest->mcpAccessTokenUid,
            'mcp_transport_session_id' => $ingest->mcpTransportSessionId,
            'mcp_reported_client_name' => McpDataEnvelope::text($ingest->mcpReportedClientName),
            'mcp_reported_client_version' => McpDataEnvelope::text($ingest->mcpReportedClientVersion),
            'provenance_supplied_at_ingest' => $ingest->provenanceSuppliedAtIngest,
            'derived_from_version_uid' => $ingest->derivedFromVersionUid,
            'content_equivalent_to_version_uid' => $ingest->contentEquivalentToVersionUid,
            'content_origin_version_uid' => $ingest->contentOriginVersionUid,
            'recorded_at' => $ingest->recordedAt->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function producer(ProducerAssertionView $producer): array
    {
        return [
            'uid' => $producer->uid,
            'kind' => $producer->kind->value,
            'name' => McpDataEnvelope::text($producer->producerName),
            'version' => McpDataEnvelope::text($producer->producerVersion),
            'provider' => McpDataEnvelope::text($producer->providerKey),
            'reported_provider' => McpDataEnvelope::text($producer->reportedProvider),
            'model_id' => McpDataEnvelope::text($producer->modelId),
            'model_label' => McpDataEnvelope::text($producer->modelLabel),
            'model_version' => McpDataEnvelope::text($producer->modelVersion),
            'generated_at' => $producer->generatedAt?->toISOString(),
            'evidence_type' => $producer->evidenceType->value,
            'identity_precision' => $producer->identityPrecision()->value,
            'extensions' => array_map($this->extension(...), $producer->claimExtensions),
            'references' => array_map($this->reference(...), $producer->references),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extension(ProducerClaimExtension $extension): array
    {
        return [
            'key' => McpDataEnvelope::text($extension->key),
            'value' => McpDataEnvelope::text($extension->value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(ExternalOriginReferenceView $reference): array
    {
        return [
            'kind' => $reference->kind->value,
            'ref' => McpDataEnvelope::text($reference->externalRef),
            'url' => McpDataEnvelope::text($reference->url),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function origin(VersionOriginView $origin): array
    {
        return [
            'page_version_uid' => $origin->versionUid,
            'version_number' => $origin->versionNumber,
            'content_hash' => $origin->contentHash,
            'producers' => array_map($this->producer(...), $origin->producers),
        ];
    }
}
