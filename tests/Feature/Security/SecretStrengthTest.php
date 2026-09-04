<?php

declare(strict_types=1);

use App\Infrastructure\Security\SecretStrength;

it('accepts strong secrets', function (string $secret): void {
    expect(SecretStrength::isStrong($secret))->toBeTrue();
})->with([
    'raw 32 bytes' => str_repeat('a', 32),
    'base64 32 bytes' => 'base64:' . base64_encode(str_repeat('b', 32)),
]);

it('rejects weak or placeholder secrets', function (string $secret): void {
    expect(SecretStrength::isStrong($secret))->toBeFalse();
})->with([
    'empty' => '',
    'short raw' => str_repeat('a', 31),
    'short base64' => 'base64:' . base64_encode('short'),
    'invalid base64' => 'base64:%%%not-base64%%%',
    'placeholder marker' => 'replace-with-a-real-32-byte-secret-value',
    'change-me marker' => 'change-me-' . str_repeat('x', 40),
]);

it('rejects secrets published in the repository fixtures', function (): void {
    $e2eAppKey = 'base64:YXJ0aWZhY3RmbG93LWUyZS1hcHAta2V5LTAwMDAwMDA=';
    $publishedSigningKey = 'artifact-preview-test-signing-key';

    expect(SecretStrength::isPublishedFixtureSecret($e2eAppKey))->toBeTrue()
        ->and(SecretStrength::isStrong($e2eAppKey))->toBeFalse()
        // Matched by decoded bytes too, not only the exact base64 string.
        ->and(SecretStrength::isPublishedFixtureSecret('artifactflow-e2e-app-key-0000000'))->toBeTrue()
        // Runtime test signing still needs isStrong(), but production consumers
        // must reject both its raw and base64-equivalent representations.
        ->and(SecretStrength::isStrong($publishedSigningKey))->toBeTrue()
        ->and(SecretStrength::isProductionSafe($publishedSigningKey))->toBeFalse()
        ->and(SecretStrength::isProductionSafe('base64:' . base64_encode($publishedSigningKey)))->toBeFalse()
        // A genuinely random 32-byte key is still accepted.
        ->and(SecretStrength::isProductionSafe('base64:' . base64_encode(random_bytes(32))))->toBeTrue();
});

it('rejects every published processor fallback in production secret roles', function (string $secret): void {
    expect(SecretStrength::isPublishedFixtureSecret($secret))->toBeTrue()
        ->and(SecretStrength::isStrong($secret))->toBeFalse()
        ->and(SecretStrength::isProductionSafe($secret))->toBeFalse();
})->with([
    'local XLSX' => 'artifactflow-local-xlsx-processor-secret-not-for-production',
    'local DOCX' => 'artifactflow-local-docx-processor-secret-not-for-production',
    'e2e image' => 'artifactflow-e2e-image-parser-secret',
    'e2e PDF' => 'artifactflow-e2e-pdf-processor-secret',
    'e2e XLSX' => 'artifactflow-e2e-xlsx-processor-secret',
    'e2e DOCX' => 'artifactflow-e2e-docx-processor-secret',
    'runtime PDF' => 'artifactflow-private-processor-runtime-test-secret',
    'runtime XLSX' => 'artifactflow-xlsx-runtime-test-secret',
    'runtime DOCX' => 'artifactflow-docx-runtime-test-secret',
]);

it('normalizes base64 secrets to their raw bytes', function (): void {
    expect(SecretStrength::normalized('base64:' . base64_encode('payload')))->toBe('payload')
        ->and(SecretStrength::normalized('plain'))->toBe('plain')
        ->and(SecretStrength::normalized(''))->toBeNull()
        ->and(SecretStrength::normalized('base64:%%%'))->toBeNull();
});
