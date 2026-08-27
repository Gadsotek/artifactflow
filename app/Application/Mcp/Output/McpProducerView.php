<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpProducerView implements McpWirePayload
{
    /**
     * @param list<McpProducerExtensionView> $extensions
     * @param list<McpExternalReferenceView> $references
     */
    public function __construct(
        public string $uid,
        public string $kind,
        public ?McpUntrustedText $name,
        public ?McpUntrustedText $version,
        public ?McpUntrustedText $provider,
        public ?McpUntrustedText $reportedProvider,
        public ?McpUntrustedText $modelId,
        public ?McpUntrustedText $modelLabel,
        public ?McpUntrustedText $modelVersion,
        public ?string $generatedAt,
        public string $evidenceType,
        public string $identityPrecision,
        public array $extensions,
        public array $references,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'uid' => $this->uid,
            'kind' => $this->kind,
        ];

        $optional = [
            'name' => $this->name,
            'version' => $this->version,
            'provider' => $this->provider,
            'reported_provider' => $this->reportedProvider,
            'model_id' => $this->modelId,
            'model_label' => $this->modelLabel,
            'model_version' => $this->modelVersion,
        ];
        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value->toWire();
            }
        }

        if ($this->generatedAt !== null) {
            $payload['generated_at'] = $this->generatedAt;
        }

        $payload['evidence_type'] = $this->evidenceType;
        $payload['identity_precision'] = $this->identityPrecision;
        $payload['extensions'] = array_map(
            static fn (McpProducerExtensionView $extension): array => $extension->toWire(),
            $this->extensions,
        );
        $payload['references'] = array_map(
            static fn (McpExternalReferenceView $reference): array => $reference->toWire(),
            $this->references,
        );

        return $payload;
    }
}
