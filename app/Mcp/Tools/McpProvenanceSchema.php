<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Provenance\VersionProvenanceRules;
use App\Domain\Provenance\ExternalReferenceKind;
use App\Domain\Provenance\ProducerKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

final readonly class McpProvenanceSchema
{
    public function make(JsonSchema $schema): ObjectType
    {
        $reference = $schema->object([
            'kind' => $schema->string()->enum(ExternalReferenceKind::class)->required(),
            'ref' => $schema->string()->description('Optional opaque external reference.'),
            'url' => $schema->string()->description('Optional HTTPS URL without embedded credentials.'),
        ])->withoutAdditionalProperties();

        $producer = $schema->object([
            'kind' => $schema->string()->enum(ProducerKind::class)->required(),
            'name' => $schema->string()->description('Required for software; optional for a human.'),
            'version' => $schema->string()->description('Optional software version.'),
            'provider' => $schema->string()->description('Required when kind is ai.'),
            'model_id' => $schema->string()
                ->description('Exact provider-defined model identifier; required when kind is ai.'),
            'model_label' => $schema->string()->description('Optional human-readable model label.'),
            'model_version' => $schema->string()->description('Optional provider-exposed model snapshot/version.'),
            'generated_at' => $schema->string()->description(
                'Optional exact RFC 3339 generation timestamp with up to six fractional-second digits.',
            ),
            'references' => $schema->array()
                ->items($reference)
                ->max(VersionProvenanceRules::MAX_REFERENCES_PER_PRODUCER),
        ])->withoutAdditionalProperties();

        return $schema->object([
            'producers' => $schema->array()
                ->items($producer)
                ->max(VersionProvenanceRules::MAX_PRODUCERS),
        ])
            ->description(
                'Optional self-reported content provenance. Omit it when the producer is not known; '
                . 'ArtifactFlow never infers a model from the MCP client.',
            )
            ->withoutAdditionalProperties();
    }
}
