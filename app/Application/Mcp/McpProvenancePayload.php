<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpExternalReferenceView;
use App\Application\Mcp\Output\McpProducerExtensionView;
use App\Application\Mcp\Output\McpProducerView;
use App\Application\Mcp\Output\McpProvenanceView;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\Mcp\Output\McpVersionIngestPayload;
use App\Application\Mcp\Output\McpVersionOriginPayload;
use App\Application\Provenance\ExternalOriginReferenceView as SourceExternalOriginReferenceView;
use App\Application\Provenance\PageVersionProvenanceView;
use App\Application\Provenance\ProducerAssertionView;
use App\Application\Provenance\ProducerClaimExtension;
use App\Application\Provenance\VersionIngestView;
use App\Application\Provenance\VersionOriginView;
use LogicException;

final readonly class McpProvenancePayload
{
    public function make(PageVersionProvenanceView $view): McpProvenanceView
    {
        /** @var array<string, McpProducerView> $definitions */
        $definitions = [];
        /** @var list<McpProducerView> $producers */
        $producers = [];
        $pageOriginProducerUids = $this->producerUids(
            $view->pageOriginProducers,
            $definitions,
            $producers,
        );
        $directVersionProducerUids = $this->producerUids(
            $view->directVersionProducers,
            $definitions,
            $producers,
        );
        $effectiveContentOrigin = null;

        if ($view->effectiveContentOrigin instanceof VersionOriginView) {
            $origin = $view->effectiveContentOrigin;
            $effectiveContentOrigin = new McpVersionOriginPayload(
                pageVersionUid: $origin->versionUid,
                versionNumber: $origin->versionNumber,
                contentHash: $origin->contentHash,
                producerUids: $this->producerUids($origin->producers, $definitions, $producers),
            );
        }

        return new McpProvenanceView(
            completeness: $view->completeness->value,
            strongestEvidence: $view->strongestEvidence !== null
                ? $view->strongestEvidence->value
                : 'none',
            versionIngest: $view->versionIngest instanceof VersionIngestView
                ? $this->ingest($view->versionIngest)
                : null,
            producers: $producers,
            pageOriginProducerUids: $pageOriginProducerUids,
            directVersionProducerUids: $directVersionProducerUids,
            effectiveContentOrigin: $effectiveContentOrigin,
        );
    }

    private function ingest(VersionIngestView $ingest): McpVersionIngestPayload
    {
        return new McpVersionIngestPayload(
            uid: $ingest->uid,
            pageVersionUid: $ingest->pageVersionUid,
            versionNumber: $ingest->versionNumber,
            contentHash: $ingest->contentHash,
            operation: $ingest->operation->value,
            ingestMethod: $ingest->ingestMethod->value,
            actorUserUid: $ingest->actorUserUid,
            actorName: McpUntrustedText::fromNullable($ingest->actorName),
            mcpAccessTokenUid: $ingest->mcpAccessTokenUid,
            mcpTransportSessionId: $ingest->mcpTransportSessionId,
            mcpReportedClientName: $ingest->mcpReportedClientName === null
                ? null
                : new McpUntrustedText($ingest->mcpReportedClientName),
            mcpReportedClientVersion: $ingest->mcpReportedClientVersion === null
                ? null
                : new McpUntrustedText($ingest->mcpReportedClientVersion),
            provenanceSuppliedAtIngest: $ingest->provenanceSuppliedAtIngest,
            derivedFromVersionUid: $ingest->derivedFromVersionUid,
            contentEquivalentToVersionUid: $ingest->contentEquivalentToVersionUid,
            contentOriginVersionUid: $ingest->contentOriginVersionUid,
            recordedAt: $ingest->recordedAt->toISOString()
                ?? throw new LogicException('Version ingest has no recorded timestamp.'),
        );
    }

    public function producer(ProducerAssertionView $producer): McpProducerView
    {
        return new McpProducerView(
            uid: $producer->uid,
            kind: $producer->kind->value,
            name: $producer->producerName === null ? null : new McpUntrustedText($producer->producerName),
            version: $producer->producerVersion === null ? null : new McpUntrustedText($producer->producerVersion),
            provider: $producer->providerKey === null ? null : new McpUntrustedText($producer->providerKey),
            reportedProvider: $producer->reportedProvider === null
                ? null
                : new McpUntrustedText($producer->reportedProvider),
            modelId: $producer->modelId === null ? null : new McpUntrustedText($producer->modelId),
            modelLabel: $producer->modelLabel === null ? null : new McpUntrustedText($producer->modelLabel),
            modelVersion: $producer->modelVersion === null
                ? null
                : new McpUntrustedText($producer->modelVersion),
            generatedAt: $producer->generatedAt?->toISOString(),
            evidenceType: $producer->evidenceType->value,
            identityPrecision: $producer->identityPrecision()->value,
            extensions: array_map($this->extension(...), $producer->claimExtensions),
            references: array_map($this->reference(...), $producer->references),
        );
    }

    private function extension(ProducerClaimExtension $extension): McpProducerExtensionView
    {
        return new McpProducerExtensionView(
            key: new McpUntrustedText($extension->key),
            value: new McpUntrustedText($extension->value),
        );
    }

    private function reference(SourceExternalOriginReferenceView $reference): McpExternalReferenceView
    {
        return new McpExternalReferenceView(
            kind: $reference->kind->value,
            reference: $reference->externalRef === null ? null : new McpUntrustedText($reference->externalRef),
            url: $reference->url === null ? null : new McpUntrustedText($reference->url),
        );
    }

    /**
     * @param list<ProducerAssertionView> $source
     * @param array<string, McpProducerView> $definitions
     * @param list<McpProducerView> $catalog
     *
     * @return list<string>
     */
    private function producerUids(array $source, array &$definitions, array &$catalog): array
    {
        $uids = [];

        foreach ($source as $sourceProducer) {
            $producer = $this->producer($sourceProducer);
            $existing = $definitions[$producer->uid] ?? null;

            if ($existing !== null && $existing->toWire() !== $producer->toWire()) {
                throw new LogicException(sprintf(
                    'Conflicting MCP producer definitions share UID [%s].',
                    $producer->uid,
                ));
            }

            if ($existing === null) {
                $definitions[$producer->uid] = $producer;
                $catalog[] = $producer;
            }

            $uids[] = $producer->uid;
        }

        return $uids;
    }
}
