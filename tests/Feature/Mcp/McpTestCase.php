<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpIssuedAccessToken;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Category;
use App\Models\InstallationSettings;
use App\Models\McpAccessToken;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\FakesImageParser;
use Tests\TestCase;

abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;
    use FakesImageParser;

    protected const string PDF_PROCESSOR_SECRET = 'test-mcp-pdf-processor-secret-00000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageParser();
    }

    protected function createUser(string $name, string $email, bool $isSystemAdmin = false): User
    {
        $user = User::query()->forceCreate([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('correct horse battery staple'),
        ]);

        if ($isSystemAdmin) {
            $user->forceFill([
                'is_system_admin' => true,
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            ])->save();
        }

        return $user;
    }

    protected function createServiceAccount(string $name, string $email): User
    {
        $user = $this->createUser($name, $email);
        $user->forceFill(['is_service_account' => true])->save();

        return $user;
    }

    /**
     * @param int<0, 255> $red
     */
    protected function mcpTestPng(int $red = 16): string
    {
        $image = imagecreatetruecolor(2, 1);
        $this->assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, $red, 96, 192);
        $this->assertIsInt($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    protected function enablePdfProcessor(): void
    {
        config([
            'pdf_processor.enabled' => true,
            'pdf_processor.url' => 'http://pdf-processor.test',
            'pdf_processor.shared_secret' => self::PDF_PROCESSOR_SECRET,
            'pdf_processor.connect_timeout_seconds' => 2,
            'pdf_processor.timeout_seconds' => 15,
        ]);
        Cache::lock(\App\Application\PageCatalog\PdfProcessingAdmission::SLOT_KEY)
            ->forceRelease();
    }

    /** @param list<array{text: string, pages: int, version: string, state: string}> $responses */
    protected function fakePdfProcessorSequence(array $responses): void
    {
        Http::swap(new HttpFactory(app('events')));
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$responses): \GuzzleHttp\Promise\PromiseInterface {
            $next = array_shift($responses);

            if (!is_array($next)) {
                return Http::response(['error' => 'unexpected_test_request'], 500);
            }

            $nonceHeader = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $nonce = is_string($nonceHeader) ? $nonceHeader : '';
            $body = json_encode([
                'page_count' => $next['pages'],
                'pdf_version' => $next['version'],
                'extraction_state' => $next['state'],
                'processor_profile' => 'pdfbox-3.0.8-native-text-v1',
                'text' => $next['text'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return Http::response($body, 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-ArtifactFlow-Processor-Signature' => \App\Application\PageCatalog\PdfProcessorProtocol::responseSignature(
                    $nonce,
                    hash('sha256', $request->body()),
                    $body,
                    self::PDF_PROCESSOR_SECRET,
                ),
            ]);
        });
    }

    protected function enableTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            'two_factor_last_used_timestep' => null,
        ])->save();

        return $user->refresh();
    }

    protected function enableExternalSharing(int $maxExpiryHours = 168): void
    {
        InstallationSettings::query()->forceCreate([
            ...app(InstallationLimitSettings::class)->current()->toPersistenceArray(),
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => true,
            'external_share_acknowledgement_required' => true,
            'external_share_max_expiry_hours' => $maxExpiryHours,
        ]);
    }

    protected function addMember(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        $membership = WorkspaceMembership::query()->firstOrNew([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
        ]);
        $membership->forceFill([
            'role' => $role,
            'accepted_at' => now(),
        ])->save();
        app(\App\Application\PageCatalog\PageAccess::class)->flushCache();
    }

    /**
     * @param list<string> $tagNames
     */
    protected function createPageWithApprovedStatus(
        User $actor,
        Workspace $workspace,
        string $title,
        string $content,
        ?string $description = 'Safe summary.',
        PageType $type = PageType::Markdown,
        ?string $categoryUid = null,
        array $tagNames = [],
        ?string $parentPageUid = null,
    ): Page {
        $page = app(CreatePage::class)->handle($actor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: $type,
            title: $title,
            description: $description,
            content: $content,
            status: PageStatus::Approved,
            categoryUid: $categoryUid,
            parentPageUid: $parentPageUid,
            tagNames: $tagNames,
        ));

        return $page->refresh();
    }

    protected function createCategory(Workspace $workspace, User $creator, string $name): Category
    {
        return Category::query()->create([
            'workspace_uid' => $workspace->uid,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'created_by_user_uid' => $creator->uid,
        ]);
    }

    protected function grantUserPageAccess(Page $page, User $subject, User $grantedBy, WorkspaceRole $role): void
    {
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $subject->uid,
            'role' => $role,
            'granted_by_user_uid' => $grantedBy->uid,
        ]);
        app(PageAccess::class)->flushCache();
    }

    /**
     * @param callable(): void $callback
     */
    protected function withMcpContext(McpAccessToken $token, callable $callback): void
    {
        $context = app(McpRequestContext::class);
        $context->activate($token, 'authority-test');

        try {
            $callback();
        } finally {
            $context->clear();
        }
    }

    /**
     * @param list<string> $scopes
     * @param list<string>|null $workspaceUids
     */
    protected function issueToken(
        User $principal,
        array $scopes,
        ?Carbon $expiresAt = null,
        ?array $workspaceUids = null,
    ): McpIssuedAccessToken {
        return app(McpAccessTokenIssuer::class)->issue(
            principal: $principal,
            name: 'Test token',
            scopes: $scopes,
            expiresAt: $expiresAt ?? now()->addHour(),
            workspaceUids: $workspaceUids,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return TestResponse<Response>
     */
    protected function callTool(
        string $token,
        string $name,
        array $arguments = [],
        string $sessionId = 'test-session',
    ): TestResponse {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'MCP-Session-Id' => $sessionId,
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 'call-' . $name,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<Response>
     */
    protected function postMcp(string $token, array $body): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'MCP-Session-Id' => 'test-session',
        ])->postJson('/mcp', $body);
    }

    /**
     * @return TestResponse<Response>
     */
    protected function postJsonRpc(
        string $token,
        string $method,
        string $id = 'request',
    ): TestResponse {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'MCP-Session-Id' => 'test-session',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
        ]);
    }

    /**
     * @param TestResponse<Response> $response
     *
     * @return array<string, mixed>
     */
    protected function jsonRpcErrorPayload(TestResponse $response): array
    {
        $response->assertOk();
        $error = $response->json('error');
        $this->assertIsArray($error);

        /** @var array<string, mixed> $error */
        return $error;
    }

    /**
     * @param TestResponse<Response> $response
     *
     * @return array<string, mixed>
     */
    protected function successfulToolPayload(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse(
            (bool) $response->json('result.isError'),
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );
        $text = $response->json('result.content.0.text');
        $this->assertIsString($text);
        $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param TestResponse<Response> $response
     *
     * @return array<string, mixed>
     */
    protected function toolErrorPayload(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $text = $response->json('result.content.0.text');
        $this->assertIsString($text);
        $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['error']);

        /** @var array<string, mixed> $error */
        $error = $payload['error'];

        return $error;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    protected function payloadList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        $this->assertIsArray($value);
        $items = array_values($value);

        foreach ($items as $item) {
            $this->assertIsArray($item);
        }

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function payloadArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        $this->assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function payloadString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }
}
