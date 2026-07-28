<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Mcp\McpRequestContext;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\Provenance\ExternalReferenceRetentionClass;
use App\Domain\Provenance\ProvenanceEvidenceType;
use App\Domain\Provenance\VersionOperation;
use App\Models\ExternalOriginReference;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionIngest;
use App\Models\ProducerAssertion;

final readonly class RecordPageVersionProvenance
{
    public function __construct(
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpRequestContext $mcpContext,
    ) {
    }

    public function record(
        Page $page,
        PageVersion $version,
        string $actorUid,
        PageVersionSource $source,
        VersionOperation $operation,
        ?VersionProvenanceInput $declared,
        ?VersionLineage $lineage,
    ): PageVersionIngest {
        $contentEquivalentToVersionUid = $lineage !== null
            && hash_equals($lineage->sourceContentHash, $version->content_hash)
                ? $lineage->sourceVersionUid
                : null;
        $contentOriginVersionUid = $this->contentOriginVersionUid(
            page: $page,
            version: $version,
            contentEquivalentToVersionUid: $contentEquivalentToVersionUid,
        );
        $ingest = PageVersionIngest::query()->forceCreate([
            'page_uid' => $page->uid,
            'page_version_uid' => $version->uid,
            'content_origin_version_uid' => $contentOriginVersionUid,
            'version_number' => $version->version_number,
            'content_hash' => $version->content_hash,
            'operation' => $operation,
            'ingest_method' => $source,
            'actor_user_uid' => $actorUid,
            'mcp_access_token_uid' => $this->mcpContext->accessTokenUid(),
            'mcp_transport_session_id' => $this->mcpContext->agentSessionId(),
            'mcp_client_reported_name' => $this->mcpContext->clientReportedName(),
            'mcp_client_reported_version' => $this->mcpContext->clientReportedVersion(),
            'provenance_supplied_at_ingest' => $declared?->wasSupplied() ?? false,
            'derived_from_version_uid' => $lineage?->sourceVersionUid,
            'content_equivalent_to_version_uid' => $contentEquivalentToVersionUid,
            'recorded_at' => now(),
        ]);

        foreach ($declared !== null ? $declared->producers : [] as $producer) {
            $this->recordAssertion($page, $version, $ingest, $producer, $actorUid);
        }

        return $ingest;
    }

    private function recordAssertion(
        Page $page,
        PageVersion $version,
        PageVersionIngest $ingest,
        ProducerAssertionInput $producer,
        string $actorUid,
    ): void {
        $assertion = ProducerAssertion::query()->forceCreate([
            'page_version_ingest_uid' => $ingest->uid,
            'producer_kind' => $producer->kind,
            'producer_name' => $producer->producerName,
            'producer_version' => $producer->producerVersion,
            'provider_key' => $producer->providerKey,
            'model_id' => $producer->modelId,
            'model_label' => $producer->modelLabel,
            'model_version' => $producer->modelVersion,
            'generated_at' => $producer->generatedAt,
            'evidence_type' => ProvenanceEvidenceType::SelfReported,
            'asserted_by_user_uid' => $actorUid,
            'supersedes_assertion_uid' => null,
            'asserted_at' => now(),
        ]);

        foreach ($producer->references as $reference) {
            ExternalOriginReference::query()->forceCreate([
                'producer_assertion_uid' => $assertion->uid,
                'reference_kind' => $reference->kind,
                'external_ref' => $reference->externalRef,
                'url' => $reference->url,
                'retention_class' => ExternalReferenceRetentionClass::Sensitive,
                'redacted_at' => null,
                'redacted_by_user_uid' => null,
            ]);
        }

        $event = $this->events->record(
            eventType: DomainEventType::PageVersionProducerAsserted,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'page_version_uid' => $version->uid,
                'producer_assertion_uid' => $assertion->uid,
                'producer_kind' => $producer->kind->value,
                'evidence_type' => ProvenanceEvidenceType::SelfReported->value,
                'reference_count' => count($producer->references),
                'asserted_by_user_uid' => $actorUid,
            ] + $this->mcpContext->auditMetadata(),
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'producer_assertion',
            auditableUid: $assertion->uid,
            action: DomainEventType::PageVersionProducerAsserted,
            summary: 'Page version producer provenance asserted.',
            metadata: [
                'page_uid' => $page->uid,
                'page_version_uid' => $version->uid,
                'producer_kind' => $producer->kind->value,
                'evidence_type' => ProvenanceEvidenceType::SelfReported->value,
                'reference_count' => count($producer->references),
            ] + $this->mcpContext->auditMetadata(),
        );
    }

    private function contentOriginVersionUid(
        Page $page,
        PageVersion $version,
        ?string $contentEquivalentToVersionUid,
    ): string {
        if ($contentEquivalentToVersionUid === null) {
            return $version->uid;
        }

        $sourceIngest = PageVersionIngest::query()
            ->where('page_uid', $page->uid)
            ->where('page_version_uid', $contentEquivalentToVersionUid)
            ->first(['content_origin_version_uid']);

        if (!$sourceIngest instanceof PageVersionIngest) {
            return $contentEquivalentToVersionUid;
        }

        return $sourceIngest->content_origin_version_uid;
    }
}
