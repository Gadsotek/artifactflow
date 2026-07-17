<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\ArtifactDraftPreviewCapabilities;
use App\Application\PageCatalog\ArtifactPreviewConfiguration;
use Illuminate\Support\Carbon;
use JsonException;
use Tests\TestCase;

final class ArtifactDraftPreviewCapabilitiesFuzzTest extends TestCase
{
    private const string ARTIFACT_ORIGIN = 'https://artifacts.example.test';

    private const string SIGNATURE_CONTEXT = "artifactflow-draft-preview-capability-v1\n";

    private const string SIGNING_KEY = 'capability-fuzz-signing-key-0001';

    private const string WORKSPACE_UID = '01J00000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00 UTC'));
        config([
            'app.artifact_url' => self::ARTIFACT_ORIGIN,
            'app.artifact_url_signing_key' => self::SIGNING_KEY,
            'app.artifact_preview_url_ttl_seconds' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_single_character_payload_and_signature_mutation_is_rejected(): void
    {
        $content = '<p>mutation corpus</p>';
        $capabilities = $this->capabilities();
        $token = $capabilities->issue(self::WORKSPACE_UID, strlen($content), hash('sha256', $content))->token;
        [$encodedPayload, $signature] = explode('.', $token, 2);
        $this->assertTrue($capabilities->hasValidEnvelope($token), 'The pristine mutation control must be accepted.');

        for ($offset = 0; $offset < strlen($encodedPayload); ++$offset) {
            $mutatedPayload = $this->replaceCharacter($encodedPayload, $offset, 'A', 'B');

            $this->assertFalse(
                $capabilities->hasValidEnvelope($mutatedPayload . '.' . $signature),
                sprintf('Accepted a payload mutation at byte %d.', $offset),
            );
        }

        for ($offset = 0; $offset < strlen($signature); ++$offset) {
            $mutatedSignature = $this->replaceCharacter($signature, $offset, '0', '1');

            $this->assertFalse(
                $capabilities->hasValidEnvelope($encodedPayload . '.' . $mutatedSignature),
                sprintf('Accepted a signature mutation at nibble %d.', $offset),
            );
        }
    }

    public function test_structurally_malformed_token_corpus_fails_closed(): void
    {
        $content = '<p>structure corpus</p>';
        $capabilities = $this->capabilities();
        $token = $capabilities->issue(self::WORKSPACE_UID, strlen($content), hash('sha256', $content))->token;
        [$encodedPayload, $signature] = explode('.', $token, 2);
        $this->assertTrue($capabilities->hasValidEnvelope($token), 'The pristine structure control must be accepted.');

        $malformedTokens = [
            'empty' => '',
            'whitespace only' => " \n\t",
            'leading whitespace' => ' ' . $token,
            'trailing whitespace' => $token . ' ',
            'nul prefix' => "\0" . $token,
            'missing separator' => $encodedPayload . $signature,
            'missing payload' => '.' . $signature,
            'missing signature' => $encodedPayload . '.',
            'extra segment' => $token . '.extra',
            'base64 padding' => $encodedPayload . '=.' . $signature,
            'standard base64 alphabet' => '+' . substr($encodedPayload, 1) . '.' . $signature,
            'uppercase signature' => $encodedPayload . '.' . strtoupper($signature),
            'short signature' => $encodedPayload . '.' . substr($signature, 1),
            'long signature' => $encodedPayload . '.' . $signature . '0',
            'non-hex signature' => $encodedPayload . '.' . str_repeat('g', 64),
            'maximum payload with forged signature' => str_repeat('A', 640) . '.' . str_repeat('0', 64),
            'oversized token' => str_repeat('A', 769),
        ];

        foreach ($malformedTokens as $name => $malformedToken) {
            $this->assertFalse(
                $capabilities->hasValidEnvelope($malformedToken),
                sprintf('Accepted malformed corpus case "%s".', $name),
            );
        }
    }

    public function test_even_correctly_signed_noncanonical_or_invalid_claims_are_rejected(): void
    {
        $content = '<p>signed claim corpus</p>';
        $validClaims = $this->validClaims($content);
        $missingNonce = $validClaims;
        unset($missingNonce['nonce']);
        $reorderedClaims = [
            'purpose' => $validClaims['purpose'],
            'v' => $validClaims['v'],
            'origin' => $validClaims['origin'],
            'workspace_uid' => $validClaims['workspace_uid'],
            'expires' => $validClaims['expires'],
            'nonce' => $validClaims['nonce'],
            'content_bytes' => $validClaims['content_bytes'],
            'content_sha256' => $validClaims['content_sha256'],
        ];
        $invalidClaims = [
            'missing claim' => $missingNonce,
            'extra claim' => [...$validClaims, 'extra' => true],
            'reordered claims' => $reorderedClaims,
            'string version' => [...$validClaims, 'v' => '1'],
            'wrong purpose' => [...$validClaims, 'purpose' => 'saved-preview'],
            'wrong origin' => [...$validClaims, 'origin' => 'https://other.example.test'],
            'invalid workspace uid' => [...$validClaims, 'workspace_uid' => 'not-a-ulid'],
            'string expiry' => [...$validClaims, 'expires' => (string) $validClaims['expires']],
            'expired' => [...$validClaims, 'expires' => Carbon::now()->subSecond()->getTimestamp()],
            'expiry beyond hard maximum' => [
                ...$validClaims,
                'expires' => Carbon::now()->addSeconds(ArtifactPreviewConfiguration::MAX_TTL_SECONDS + 1)->getTimestamp(),
            ],
            'uppercase nonce' => [...$validClaims, 'nonce' => strtoupper($validClaims['nonce'])],
            'short nonce' => [...$validClaims, 'nonce' => substr($validClaims['nonce'], 1)],
            'zero content bytes' => [...$validClaims, 'content_bytes' => 0],
            'string content bytes' => [...$validClaims, 'content_bytes' => (string) $validClaims['content_bytes']],
            'uppercase content hash' => [
                ...$validClaims,
                'content_sha256' => strtoupper($validClaims['content_sha256']),
            ],
        ];
        $capabilities = $this->capabilities();

        foreach ($invalidClaims as $name => $claims) {
            $this->assertFalse(
                $capabilities->hasValidEnvelope($this->signJson($claims)),
                sprintf('Accepted correctly signed invalid claims case "%s".', $name),
            );
        }

        foreach (['truncated JSON' => '{"v":', 'JSON scalar' => 'true', 'excessive nesting' => str_repeat('[', 17) . str_repeat(']', 17)] as $name => $json) {
            $this->assertFalse(
                $capabilities->hasValidEnvelope($this->signPayload($json)),
                sprintf('Accepted correctly signed malformed JSON case "%s".', $name),
            );
        }
    }

    public function test_capability_matching_is_bound_to_exact_content_bytes(): void
    {
        $content = " <p>café</p>\r\n<script>void 0</script> ";
        $capabilities = $this->capabilities();
        $token = $capabilities->issue(self::WORKSPACE_UID, strlen($content), hash('sha256', $content))->token;

        $this->assertTrue($capabilities->matches($token, $content));

        $mutations = [
            'same-length byte replacement' => substr_replace($content, 'X', 1, 1),
            'trailing whitespace' => $content . ' ',
            'newline normalization' => str_replace("\r\n", "\n", $content),
            'leading byte-order mark' => "\xEF\xBB\xBF" . $content,
            'different Unicode normalization' => str_replace('é', "e\u{0301}", $content),
        ];

        foreach ($mutations as $name => $mutation) {
            $this->assertFalse(
                $capabilities->matches($token, $mutation),
                sprintf('Accepted content mutation "%s".', $name),
            );
        }
    }

    private function capabilities(): ArtifactDraftPreviewCapabilities
    {
        return app(ArtifactDraftPreviewCapabilities::class);
    }

    private function replaceCharacter(string $value, int $offset, string $firstChoice, string $secondChoice): string
    {
        $replacement = $value[$offset] === $firstChoice ? $secondChoice : $firstChoice;

        return substr($value, 0, $offset) . $replacement . substr($value, $offset + 1);
    }

    /**
     * @return array{
     *     v: int,
     *     purpose: string,
     *     origin: string,
     *     workspace_uid: string,
     *     expires: int,
     *     nonce: string,
     *     content_bytes: int,
     *     content_sha256: string
     * }
     */
    private function validClaims(string $content): array
    {
        return [
            'v' => 1,
            'purpose' => 'draft-preview',
            'origin' => self::ARTIFACT_ORIGIN,
            'workspace_uid' => self::WORKSPACE_UID,
            'expires' => Carbon::now()->addSeconds(30)->getTimestamp(),
            'nonce' => str_repeat('a', 32),
            'content_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
        ];
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws JsonException
     */
    private function signJson(array $claims): string
    {
        return $this->signPayload(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function signPayload(string $payload): string
    {
        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', self::SIGNATURE_CONTEXT . $encodedPayload, self::SIGNING_KEY);

        return $encodedPayload . '.' . $signature;
    }
}
