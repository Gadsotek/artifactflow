<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\Provenance\ProvenanceCompleteness;
use App\Domain\Provenance\ProvenanceEvidenceType;
use App\Models\ExternalOriginReference;
use App\Models\PageVersion;
use App\Models\PageVersionIngest;
use App\Models\ProducerAssertion;
use App\Models\User;

final readonly class ProvenanceReadModel
{
    public function forVersion(PageVersion $version): PageVersionProvenanceView
    {
        $ingest = PageVersionIngest::query()
            ->with('actor')
            ->where('page_version_uid', $version->uid)
            ->where('page_uid', $version->page_uid)
            ->first();

        if (!$ingest instanceof PageVersionIngest) {
            return new PageVersionProvenanceView(
                completeness: ProvenanceCompleteness::None,
                strongestEvidence: null,
                versionIngest: null,
                pageOriginProducers: [],
                directVersionProducers: [],
                effectiveContentOrigin: null,
            );
        }

        $pageOrigin = PageVersionIngest::query()
            ->where('page_uid', $ingest->page_uid)
            ->where('version_number', 1)
            ->first();
        $effectiveOrigin = $this->effectiveOrigin($ingest);
        $pageOriginProducers = $pageOrigin instanceof PageVersionIngest
            ? $this->activeProducers($pageOrigin)
            : [];
        $directProducers = $this->activeProducers($ingest);
        $effectiveProducers = $this->activeProducers($effectiveOrigin);
        $relevantProducers = $directProducers !== [] ? $directProducers : $effectiveProducers;

        return new PageVersionProvenanceView(
            completeness: $this->completeness($relevantProducers),
            strongestEvidence: $this->strongestEvidence($relevantProducers),
            versionIngest: $this->ingestView($ingest),
            pageOriginProducers: $pageOriginProducers,
            directVersionProducers: $directProducers,
            effectiveContentOrigin: new VersionOriginView(
                versionUid: $effectiveOrigin->page_version_uid,
                versionNumber: $effectiveOrigin->version_number,
                contentHash: $effectiveOrigin->content_hash,
                producers: $effectiveProducers,
            ),
        );
    }

    private function effectiveOrigin(PageVersionIngest $ingest): PageVersionIngest
    {
        if ($ingest->content_origin_version_uid === $ingest->page_version_uid) {
            return $ingest;
        }

        $origin = PageVersionIngest::query()
            ->where('page_uid', $ingest->page_uid)
            ->where('page_version_uid', $ingest->content_origin_version_uid)
            ->first();

        return $origin instanceof PageVersionIngest ? $origin : $ingest;
    }

    /**
     * @return list<ProducerAssertionView>
     */
    private function activeProducers(PageVersionIngest $ingest): array
    {
        $assertions = ProducerAssertion::query()
            ->with('references')
            ->where('page_version_ingest_uid', $ingest->uid)
            ->doesntHave('supersededBy')
            ->orderBy('asserted_at')
            ->get();
        $views = [];

        foreach ($assertions as $assertion) {
            $references = [];

            foreach ($assertion->references->whereNull('redacted_at')->sortBy('created_at') as $reference) {
                $references[] = $this->referenceView($reference);
            }

            $views[] = new ProducerAssertionView(
                uid: $assertion->uid,
                kind: $assertion->producer_kind,
                producerName: $assertion->producer_name,
                producerVersion: $assertion->producer_version,
                providerKey: $assertion->provider_key,
                modelId: $assertion->model_id,
                modelLabel: $assertion->model_label,
                modelVersion: $assertion->model_version,
                generatedAt: $assertion->generated_at,
                evidenceType: $assertion->evidence_type,
                references: $references,
            );
        }

        return $views;
    }

    private function referenceView(ExternalOriginReference $reference): ExternalOriginReferenceView
    {
        return new ExternalOriginReferenceView(
            kind: $reference->reference_kind,
            externalRef: $reference->external_ref,
            url: $reference->url,
        );
    }

    private function ingestView(PageVersionIngest $ingest): VersionIngestView
    {
        return new VersionIngestView(
            uid: $ingest->uid,
            pageVersionUid: $ingest->page_version_uid,
            versionNumber: $ingest->version_number,
            contentHash: $ingest->content_hash,
            operation: $ingest->operation,
            ingestMethod: $ingest->ingest_method,
            actorUserUid: $ingest->actor_user_uid,
            actorName: $ingest->actor instanceof User ? $ingest->actor->name : 'Unknown user',
            mcpAccessTokenUid: $ingest->mcp_access_token_uid,
            mcpTransportSessionId: $ingest->mcp_transport_session_id,
            mcpReportedClientName: $ingest->mcp_client_reported_name,
            mcpReportedClientVersion: $ingest->mcp_client_reported_version,
            provenanceSuppliedAtIngest: $ingest->provenance_supplied_at_ingest,
            derivedFromVersionUid: $ingest->derived_from_version_uid,
            contentEquivalentToVersionUid: $ingest->content_equivalent_to_version_uid,
            contentOriginVersionUid: $ingest->content_origin_version_uid,
            recordedAt: $ingest->recorded_at,
        );
    }

    /**
     * @param list<ProducerAssertionView> $producers
     */
    private function completeness(array $producers): ProvenanceCompleteness
    {
        if ($producers === []) {
            return ProvenanceCompleteness::None;
        }

        foreach ($producers as $producer) {
            if (!$producer->isIdentified()) {
                return ProvenanceCompleteness::Partial;
            }
        }

        return ProvenanceCompleteness::Complete;
    }

    /**
     * @param list<ProducerAssertionView> $producers
     */
    private function strongestEvidence(array $producers): ?ProvenanceEvidenceType
    {
        $strongest = null;

        foreach ($producers as $producer) {
            if ($strongest === null || $producer->evidenceType->strength() > $strongest->strength()) {
                $strongest = $producer->evidenceType;
            }
        }

        return $strongest;
    }
}
