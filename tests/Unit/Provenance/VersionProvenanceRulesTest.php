<?php

declare(strict_types=1);

namespace Tests\Unit\Provenance;

use App\Application\PageCatalog\PageContentScanner;
use App\Application\Provenance\ExternalOriginReferenceInput;
use App\Application\Provenance\ProducerAssertionInput;
use App\Application\Provenance\VersionProvenanceInput;
use App\Application\Provenance\VersionProvenanceRules;
use App\Domain\DomainRuleViolation;
use App\Domain\Provenance\ExternalReferenceKind;
use App\Domain\Provenance\ProducerKind;
use PHPUnit\Framework\TestCase;

final class VersionProvenanceRulesTest extends TestCase
{
    public function test_it_accepts_absent_empty_and_supported_producer_shapes(): void
    {
        $rules = new VersionProvenanceRules(new PageContentScanner());

        $rules->ensureValid(null);
        $rules->ensureValid(new VersionProvenanceInput([]));
        $rules->ensureValid(new VersionProvenanceInput([
            $this->aiProducer(references: [
                new ExternalOriginReferenceInput(
                    kind: ExternalReferenceKind::Conversation,
                    externalRef: 'conversation-123',
                    url: 'https://example.test/conversations/123',
                ),
            ]),
            new ProducerAssertionInput(
                kind: ProducerKind::Software,
                producerName: 'Invoice compiler',
                producerVersion: '2.1.0',
                providerKey: null,
                modelId: null,
                modelLabel: null,
                modelVersion: null,
                generatedAt: null,
                references: [],
            ),
            new ProducerAssertionInput(
                kind: ProducerKind::Human,
                producerName: 'Petr',
                producerVersion: null,
                providerKey: null,
                modelId: null,
                modelLabel: null,
                modelVersion: null,
                generatedAt: null,
                references: [],
            ),
        ]));

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_invalid_producer_shapes_limits_and_text(): void
    {
        $ai = $this->aiProducer();

        $this->assertRejected(
            new VersionProvenanceInput(array_fill(0, VersionProvenanceRules::MAX_PRODUCERS + 1, $ai)),
            'Provenance may contain at most 8 producers.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(producerName: 'Claude Code'),
            ]),
            'AI provenance requires a normalized provider key and exact model ID.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(providerKey: 'Anthropic'),
            ]),
            'AI provenance provider key must be a normalized lowercase slug.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(modelId: ' '),
            ]),
            'Provenance model ID is required.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(modelLabel: ' '),
            ]),
            'Provenance model label must not be blank.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                new ProducerAssertionInput(
                    kind: ProducerKind::Software,
                    producerName: null,
                    producerVersion: null,
                    providerKey: null,
                    modelId: null,
                    modelLabel: null,
                    modelVersion: null,
                    generatedAt: null,
                    references: [],
                ),
            ]),
            'Software provenance requires a name and cannot declare AI model fields.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                new ProducerAssertionInput(
                    kind: ProducerKind::Human,
                    producerName: 'Petr',
                    producerVersion: null,
                    providerKey: 'anthropic',
                    modelId: null,
                    modelLabel: null,
                    modelVersion: null,
                    generatedAt: null,
                    references: [],
                ),
            ]),
            'Human provenance cannot declare software or AI model fields.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(modelVersion: "bad\0version"),
            ]),
            'Provenance model version contains invalid text.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(modelLabel: str_repeat('x', 192)),
            ]),
            'Provenance model label must be 191 characters or fewer.',
        );
    }

    public function test_it_rejects_invalid_external_references(): void
    {
        $reference = new ExternalOriginReferenceInput(
            kind: ExternalReferenceKind::Source,
            externalRef: 'source-123',
            url: null,
        );

        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(references: array_fill(
                    0,
                    VersionProvenanceRules::MAX_REFERENCES_PER_PRODUCER + 1,
                    $reference,
                )),
            ]),
            'A producer may contain at most 8 external references.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(references: [
                    new ExternalOriginReferenceInput(
                        kind: ExternalReferenceKind::Source,
                        externalRef: null,
                        url: null,
                    ),
                ]),
            ]),
            'A provenance reference needs a reference value or HTTPS URL.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(references: [
                    new ExternalOriginReferenceInput(
                        kind: ExternalReferenceKind::Source,
                        externalRef: null,
                        url: 'http://example.test/source',
                    ),
                ]),
            ]),
            'Provenance URLs must use HTTPS and must not contain authority credentials.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(references: [
                    new ExternalOriginReferenceInput(
                        kind: ExternalReferenceKind::Source,
                        externalRef: null,
                        url: 'https://user:password@example.test/source',
                    ),
                ]),
            ]),
            'Provenance URLs must use HTTPS and must not contain authority credentials.',
        );
        $this->assertRejected(
            new VersionProvenanceInput([
                $this->aiProducer(references: [
                    new ExternalOriginReferenceInput(
                        kind: ExternalReferenceKind::Source,
                        externalRef: ' ',
                        url: null,
                    ),
                ]),
            ]),
            'Provenance external reference must not be blank.',
        );
    }

    public function test_it_rejects_obvious_credentials_in_every_persisted_provenance_text_class(): void
    {
        $secret = 'ghp_' . str_repeat('a', 30);

        foreach ([
            new VersionProvenanceInput([
                $this->aiProducer(providerKey: $secret),
            ]),
            new VersionProvenanceInput([
                $this->aiProducer(modelId: $secret),
            ]),
            new VersionProvenanceInput([
                $this->aiProducer(modelLabel: $secret),
            ]),
            new VersionProvenanceInput([
                $this->aiProducer(modelVersion: $secret),
            ]),
            new VersionProvenanceInput([
                new ProducerAssertionInput(
                    kind: ProducerKind::Software,
                    producerName: $secret,
                    producerVersion: $secret,
                    providerKey: null,
                    modelId: null,
                    modelLabel: null,
                    modelVersion: null,
                    generatedAt: null,
                    references: [],
                ),
            ]),
            new VersionProvenanceInput([
                $this->aiProducer(references: [
                    new ExternalOriginReferenceInput(
                        kind: ExternalReferenceKind::Source,
                        externalRef: $secret,
                        url: null,
                    ),
                ]),
            ]),
            new VersionProvenanceInput([
                $this->aiProducer(references: [
                    new ExternalOriginReferenceInput(
                        kind: ExternalReferenceKind::Source,
                        externalRef: null,
                        url: 'https://example.test/source?token=' . $secret,
                    ),
                ]),
            ]),
        ] as $provenance) {
            $this->assertRejected($provenance, 'Page content contains an obvious secret.');
        }
    }

    /**
     * @param list<ExternalOriginReferenceInput> $references
     */
    private function aiProducer(
        ?string $producerName = null,
        ?string $providerKey = 'anthropic',
        ?string $modelId = 'claude-opus-5-2-20260715',
        ?string $modelLabel = 'Claude Opus 5.2',
        ?string $modelVersion = '20260715',
        array $references = [],
    ): ProducerAssertionInput {
        return new ProducerAssertionInput(
            kind: ProducerKind::Ai,
            producerName: $producerName,
            producerVersion: null,
            providerKey: $providerKey,
            modelId: $modelId,
            modelLabel: $modelLabel,
            modelVersion: $modelVersion,
            generatedAt: null,
            references: $references,
        );
    }

    private function assertRejected(VersionProvenanceInput $provenance, string $message): void
    {
        try {
            (new VersionProvenanceRules(new PageContentScanner()))->ensureValid($provenance);
            $this->fail('Expected provenance validation to fail.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
