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
        $extension = $schema->object([
            'key' => $schema->string()
                ->description('Lowercase namespaced identity-metadata key; prompts, credentials, authorization data, URLs, and content payloads are forbidden.')
                ->required(),
            'value' => $schema->string()
                ->description('Short scalar identity-metadata value; use references for HTTPS URLs.')
                ->required(),
        ])->withoutAdditionalProperties();
        $reference = $schema->object([
            'kind' => $schema->string()->enum(ExternalReferenceKind::class)->required(),
            'ref' => $schema->string()->description('Optional opaque external reference.'),
            'url' => $schema->string()->description('Optional HTTPS URL without embedded credentials.'),
        ])->withoutAdditionalProperties();

        $producer = $schema->object([
            'kind' => $schema->string()->enum(ProducerKind::class)->required(),
            'name' => $schema->string()->description('Required for software; optional for a human.'),
            'version' => $schema->string()->description('Optional software version.'),
            'provider' => $schema->string()->description('Best-known AI provider name; an exact model ID is not required.'),
            'model_id' => $schema->string()
                ->description('Exact provider-defined model identifier when known; never guess this value.'),
            'model_label' => $schema->string()
                ->description('Best-known human-readable model name, family, or label when an exact model ID is unavailable.'),
            'model_version' => $schema->string()->description('Optional provider-exposed model snapshot/version.'),
            'generated_at' => $schema->string()->description(
                'Optional exact RFC 3339 generation timestamp with up to six fractional-second digits.',
            ),
            'references' => $schema->array()
                ->items($reference)
                ->max(VersionProvenanceRules::MAX_REFERENCES_PER_PRODUCER),
            'extensions' => $schema->array()
                ->description('Bounded forward-compatible identity metadata. Never place prompts, reasoning, credentials, authorization data, signed URLs, or content/blob payloads here.')
                ->items($extension)
                ->max(VersionProvenanceRules::MAX_CLAIM_EXTENSIONS_PER_PRODUCER),
        ])->withoutAdditionalProperties();

        return $schema->object([
            'producers' => $schema->array()
                ->items($producer)
                ->max(VersionProvenanceRules::MAX_PRODUCERS),
        ])
            ->description(
                'Optional self-reported content provenance. Supply every safe producer fact you know, '
                . 'even when identity is partial; never invent missing precision. ArtifactFlow never '
                . 'infers a model from the MCP client.',
            )
            ->withoutAdditionalProperties();
    }
}
