<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Provenance\ExternalOriginReferenceInput;
use App\Application\Provenance\ProducerAssertionInput;
use App\Application\Provenance\ProducerClaimExtension;
use App\Application\Provenance\VersionProvenanceInput;
use App\Application\Provenance\VersionProvenanceRules;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\Provenance\ExternalReferenceKind;
use App\Domain\Provenance\ProducerKind;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Str;
use ValueError;

final readonly class McpProvenanceArguments
{
    private const string SIGNED_URL_QUERY_PATTERN = '/(?:\A|&)(?:signature|sig|token|access_token|expires|x-amz-signature|x-amz-credential|x-amz-security-token|x-goog-signature|googleaccessid|key-pair-id)=/i';

    public function fromArguments(McpToolArguments $arguments): ?VersionProvenanceInput
    {
        $provenance = $arguments->nullableObject('provenance');

        if (!$provenance instanceof McpToolArguments) {
            return null;
        }

        $producers = [];

        foreach ($provenance->objectList('producers', VersionProvenanceRules::MAX_PRODUCERS) as $producer) {
            $producers[] = $this->producer($producer);
        }

        return new VersionProvenanceInput($producers);
    }

    private function producer(McpToolArguments $arguments): ProducerAssertionInput
    {
        $kindValue = $this->boundedRequired($arguments, 'kind', 32);
        $kind = ProducerKind::tryFrom($kindValue);

        if (!$kind instanceof ProducerKind) {
            throw new DomainRuleViolation('Provenance producer kind is unsupported.');
        }

        $producerName = $this->boundedNullable($arguments, 'name', 191);
        $producerVersion = $this->boundedNullable($arguments, 'version', 120);
        $reportedProvider = $this->boundedNullable($arguments, 'provider', 80);
        $providerKey = $reportedProvider === null ? null : $this->providerKey($reportedProvider);
        $modelId = $this->boundedNullable($arguments, 'model_id', 191);
        $modelLabel = $this->boundedNullable($arguments, 'model_label', 191);
        $modelVersion = $this->boundedNullable($arguments, 'model_version', 120);

        if ($kind === ProducerKind::Software) {
            $producerName ??= $this->boundedRequired($arguments, 'name', 191);
        }

        $references = [];

        foreach ($arguments->objectList(
            'references',
            VersionProvenanceRules::MAX_REFERENCES_PER_PRODUCER,
        ) as $reference) {
            $references[] = $this->reference($reference);
        }

        $claimExtensions = [];

        foreach ($arguments->objectList(
            'extensions',
            VersionProvenanceRules::MAX_CLAIM_EXTENSIONS_PER_PRODUCER,
        ) as $extension) {
            $claimExtensions[] = new ProducerClaimExtension(
                key: $this->boundedRequired($extension, 'key', VersionProvenanceRules::MAX_EXTENSION_KEY_LENGTH),
                value: $this->boundedRequired($extension, 'value', VersionProvenanceRules::MAX_EXTENSION_VALUE_LENGTH),
            );
        }

        return new ProducerAssertionInput(
            kind: $kind,
            producerName: $producerName,
            producerVersion: $producerVersion,
            providerKey: $providerKey,
            modelId: $modelId,
            modelLabel: $modelLabel,
            modelVersion: $modelVersion,
            generatedAt: $this->generatedAt($this->boundedNullable($arguments, 'generated_at', 64)),
            references: $references,
            reportedProvider: $reportedProvider,
            claimExtensions: $claimExtensions,
        );
    }

    private function reference(McpToolArguments $arguments): ExternalOriginReferenceInput
    {
        $kindValue = $this->boundedRequired($arguments, 'kind', 32);
        $kind = ExternalReferenceKind::tryFrom($kindValue);

        if (!$kind instanceof ExternalReferenceKind) {
            throw new DomainRuleViolation('Provenance reference kind is unsupported.');
        }

        $externalRef = $this->boundedNullable($arguments, 'ref', 512);
        $url = $this->boundedNullable($arguments, 'url', 2048);

        if ($externalRef === null && $url === null) {
            throw new DomainRuleViolation('A provenance reference needs a reference value or HTTPS URL.');
        }

        if ($url !== null) {
            $this->ensureSafeHttpsUrl($url);
        }

        return new ExternalOriginReferenceInput($kind, $externalRef, $url);
    }

    private function boundedRequired(McpToolArguments $arguments, string $key, int $maximum): string
    {
        $value = $arguments->requiredString($key);
        $this->ensureStorableAndBounded($value, $key, $maximum);

        return $value;
    }

    private function boundedNullable(McpToolArguments $arguments, string $key, int $maximum): ?string
    {
        $value = $arguments->nullableString($key);

        if ($value !== null) {
            $this->ensureStorableAndBounded($value, $key, $maximum);
        }

        return $value;
    }

    private function ensureStorableAndBounded(string $value, string $key, int $maximum): void
    {
        if (!PageContentEncoding::isStorable($value)) {
            throw new DomainRuleViolation(sprintf(
                'Provenance field [%s] contains invalid text.',
                $key,
            ));
        }

        if (mb_strlen($value) > $maximum) {
            throw new DomainRuleViolation(sprintf(
                'Provenance field [%s] must be %d characters or fewer.',
                $key,
                $maximum,
            ));
        }
    }

    private function providerKey(string $provider): string
    {
        $key = Str::slug($provider);

        if ($key === '' || mb_strlen($key) > 80) {
            throw new DomainRuleViolation('AI provider must produce a valid normalized key.');
        }

        return $key;
    }

    private function generatedAt(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, string> $parts */
        $parts = [];

        if (preg_match(
            '/\A(?<base>[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.(?<fraction>[0-9]{1,6}))?(?<offset>Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/',
            $value,
            $parts,
        ) !== 1) {
            throw new DomainRuleViolation('Provenance generated_at must be an exact RFC 3339 timestamp.');
        }

        $fraction = $parts['fraction'];
        $offset = $parts['offset'] === 'Z' ? '+00:00' : $parts['offset'];
        $normalizedValue = $parts['base']
            . ($fraction === '' ? '' : '.' . str_pad($fraction, 6, '0'))
            . $offset;
        $format = '!Y-m-d\TH:i:s' . ($fraction === '' ? '' : '.u') . 'P';

        try {
            $generatedAt = DateTimeImmutable::createFromFormat($format, $normalizedValue);
            $parseState = DateTimeImmutable::getLastErrors();
        } catch (ValueError) {
            $generatedAt = false;
            $parseState = false;
        }

        $hasParseDiagnostics = is_array($parseState)
            && ($parseState['warning_count'] > 0 || $parseState['error_count'] > 0);

        if (
            !$generatedAt instanceof DateTimeImmutable
            || $hasParseDiagnostics
            || $generatedAt->format(substr($format, 1)) !== $normalizedValue
        ) {
            throw new DomainRuleViolation('Provenance generated_at must be an exact RFC 3339 timestamp.');
        }

        return CarbonImmutable::instance($generatedAt);
    }

    private function ensureSafeHttpsUrl(string $url): void
    {
        $parts = parse_url($url);

        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            throw new DomainRuleViolation(
                'Provenance URLs must use HTTPS and must not contain authority credentials.',
            );
        }

        if (
            is_string($parts['query'] ?? null)
            && preg_match(self::SIGNED_URL_QUERY_PATTERN, rawurldecode($parts['query'])) === 1
        ) {
            throw new DomainRuleViolation('Provenance URLs must not be signed capability URLs.');
        }
    }
}
