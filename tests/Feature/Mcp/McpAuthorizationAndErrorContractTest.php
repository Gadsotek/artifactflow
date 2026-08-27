<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\McpAccessToken;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class McpAuthorizationAndErrorContractTest extends McpTestCase
{
    public function test_search_read_and_update_require_page_access_without_per_page_approval_gate(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $service = $this->createServiceAccount('Artifact Agent', 'artifact-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);

        $otherOwner = $this->createUser('Other Owner', 'other@example.test');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherOwner, 'Other Team');

        $approvedVisible = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Visible Needle',
            content: '# Visible Needle',
        );
        $accessibleButNotApproved = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Hidden Needle',
            description: 'Accessible through normal workspace authorization.',
            content: '# Hidden Needle',
        ));
        $approvedButInaccessible = $this->createPageWithApprovedStatus(
            actor: $otherOwner,
            workspace: $otherWorkspace,
            title: 'Foreign Needle',
            content: '# Foreign Needle',
        );

        $token = $this->issueToken($service, ['mcp:search', 'mcp:read', 'mcp:update'])->plainTextToken;

        $searchPayload = $this->successfulToolPayload($this->callTool($token, 'search', [
            'query' => 'Needle',
        ]));
        $results = $this->payloadList($searchPayload, 'results');
        $firstResult = $results[0];
        $firstTitle = $this->payloadArray($firstResult, 'title');

        $this->assertEqualsCanonicalizing(
            [$approvedVisible->uid, $accessibleButNotApproved->uid],
            array_column($results, 'uid'),
        );
        $this->assertArrayNotHasKey('snippet', $firstResult);
        $this->assertSame('artifactflow.untrusted_data', $firstTitle['kind']);

        $accessibleRead = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $accessibleButNotApproved->uid,
        ]));
        $inaccessibleError = $this->toolErrorPayload($this->callTool($token, 'read', [
            'page_uid' => $approvedButInaccessible->uid,
        ]));

        $this->assertSame($accessibleButNotApproved->uid, $accessibleRead['uid']);
        $this->assertSame(['type' => 'not_found', 'message' => 'Page not found.'], $inaccessibleError);

        $notApprovedUpdate = $this->successfulToolPayload($this->callTool($token, 'update', [
            'page_uid' => $accessibleButNotApproved->uid,
            'content' => '# Saved because access is the gate',
            'base_version_uid' => $accessibleButNotApproved->current_version_uid,
            'change_summary' => 'Update the accessible draft.',
        ]));
        $inaccessibleUpdate = $this->toolErrorPayload($this->callTool($token, 'update', [
            'page_uid' => $approvedButInaccessible->uid,
            'content' => '# Should not save',
            'base_version_uid' => $approvedButInaccessible->current_version_uid,
            'change_summary' => 'Attempt an inaccessible update.',
        ]));

        $this->assertSame($accessibleButNotApproved->uid, $notApprovedUpdate['page_uid']);
        $this->assertSame(['type' => 'not_found', 'message' => 'Page not found.'], $inaccessibleUpdate);
        $this->assertSame(2, PageVersion::query()->where('page_uid', $accessibleButNotApproved->uid)->count());
        $this->assertSame(1, PageVersion::query()->where('page_uid', $approvedButInaccessible->uid)->count());
    }

    public function test_auth_rejects_admin_revoked_expired_tokens_and_throttles_mcp_calls(): void
    {
        $service = $this->createServiceAccount('Auth Agent', 'auth-agent@example.test');
        $workspaceOwner = $this->createUser('Owner User', 'auth-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($workspaceOwner, 'Auth Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $valid = $this->issueToken($service, ['mcp:search']);

        $this->postJsonRpc($valid->plainTextToken, 'tools/list')->assertOk();

        $valid->accessToken->forceFill(['revoked_at' => now()])->save();
        $this->postJsonRpc($valid->plainTextToken, 'tools/list')->assertUnauthorized();

        $expired = $this->issueToken($service, ['mcp:search']);
        $expired->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->postJsonRpc($expired->plainTextToken, 'tools/list')->assertUnauthorized();

        $adminToken = $this->issueToken($service, ['mcp:search']);
        $this->addMember($workspace, $service, WorkspaceRole::Admin);
        $this->postJsonRpc($adminToken->plainTextToken, 'tools/list')->assertOk();

        $humanWithoutTwoFactor = $this->createUser('No 2FA User', 'no-2fa-mcp@example.test');
        $rawHumanToken = 'af_mcp_' . str_repeat('x', 64);
        McpAccessToken::query()->forceCreate([
            'principal_user_uid' => $humanWithoutTwoFactor->uid,
            'name' => 'Unsafe human token',
            'token_hash' => McpAccessTokenIssuer::hashToken($rawHumanToken),
            'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
            'expires_at' => now()->addHour(),
        ]);
        $this->postJsonRpc($rawHumanToken, 'tools/list')->assertUnauthorized();

        config([
            'rate_limits.mcp_pre_auth_per_minute' => 300,
            'rate_limits.mcp_per_minute' => 1,
        ]);
        $freshService = $this->createServiceAccount('Throttled Agent', 'throttled-agent@example.test');
        $freshToken = $this->issueToken($freshService, ['mcp:search']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJsonRpc($freshToken->plainTextToken, 'tools/list', id: 'one')
            ->assertOk();
        $throttledSentinel = now()->subDay()->startOfSecond();
        $freshToken->accessToken->forceFill(['last_used_at' => $throttledSentinel])->save();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->postJsonRpc($freshToken->plainTextToken, 'tools/list', id: 'two')
            ->assertStatus(429);
        $this->assertTrue($freshToken->accessToken->refresh()->last_used_at?->equalTo($throttledSentinel));

        config(['rate_limits.mcp_pre_auth_per_minute' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.43'])
            ->postJsonRpc('af_mcp_' . str_repeat('z', 64), 'tools/list', id: 'bad-one')
            ->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.43'])
            ->postJsonRpc('af_mcp_' . str_repeat('z', 64), 'tools/list', id: 'bad-two')
            ->assertStatus(429);
    }

    public function test_authenticated_token_usage_writes_are_debounced(): void
    {
        config(['rate_limits.mcp_per_minute' => 10]);

        $service = $this->createServiceAccount('Usage Agent', 'usage-agent@example.test');
        $issued = $this->issueToken($service, ['mcp:search']);
        $issued->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();
        $usageUpdates = 0;

        DB::listen(function (QueryExecuted $query) use (&$usageUpdates): void {
            $sql = strtolower($query->sql);

            if (
                str_starts_with($sql, 'update "mcp_access_tokens"')
                && str_contains($sql, '"last_used_at"')
            ) {
                $usageUpdates++;
            }
        });

        $this->postJsonRpc($issued->plainTextToken, 'tools/list', id: 'first-use')->assertOk();
        $this->postJsonRpc($issued->plainTextToken, 'tools/list', id: 'second-use')->assertOk();

        $this->assertSame(1, $usageUpdates);
    }

    public function test_authenticated_mcp_requests_are_rate_limited_per_principal_across_tokens(): void
    {
        config([
            'rate_limits.mcp_pre_auth_per_minute' => 100,
            'rate_limits.mcp_per_minute' => 1,
        ]);
        $service = $this->createServiceAccount('Principal Rate Agent', 'principal-rate-agent@example.test');
        $otherService = $this->createServiceAccount('Other Rate Agent', 'other-rate-agent@example.test');
        $firstToken = $this->issueToken($service, [McpAccessTokenIssuer::SCOPE_SEARCH]);
        $secondToken = $this->issueToken($service, [McpAccessTokenIssuer::SCOPE_SEARCH]);
        $otherToken = $this->issueToken($otherService, [McpAccessTokenIssuer::SCOPE_SEARCH]);

        $this->postJsonRpc($firstToken->plainTextToken, 'tools/list', id: 'principal-first')->assertOk();
        $this->postJsonRpc($secondToken->plainTextToken, 'tools/list', id: 'principal-second')
            ->assertTooManyRequests();
        $this->postJsonRpc($otherToken->plainTextToken, 'tools/list', id: 'other-principal')->assertOk();
    }

    public function test_protocol_and_tool_argument_errors_are_reported_without_server_errors(): void
    {
        $service = $this->createServiceAccount('Protocol Agent', 'protocol-agent@example.test');
        $token = $this->issueToken($service, ['mcp:search'])->plainTextToken;

        $initialize = $this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'init',
            'method' => 'initialize',
        ]);
        $initialize->assertOk();
        $this->assertSame('artifactflow', $initialize->json('result.serverInfo.name'));
        $instructions = $initialize->json('result.instructions');
        $this->assertIsString($instructions);
        $this->assertStringContainsString('every safe producer-provenance fact', $instructions);
        $this->assertStringContainsString('include model_id only when you know the exact', $instructions);
        $this->assertStringContainsString('returned stored_provenance', $instructions);

        $tools = $this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'tools-with-pdf',
            'method' => 'tools/list',
            'params' => ['per_page' => 50],
        ]);
        $tools->assertOk();
        $toolDefinitions = $tools->json('result.tools');
        $this->assertIsArray($toolDefinitions);
        $this->assertCount(16, $toolDefinitions);
        $this->assertContains('list_taxonomy', array_column($toolDefinitions, 'name'));
        $this->assertContains('create_category', array_column($toolDefinitions, 'name'));
        $this->assertContains('create_tag', array_column($toolDefinitions, 'name'));
        $this->assertContains('organize', array_column($toolDefinitions, 'name'));
        $this->assertContains('create_image', array_column($toolDefinitions, 'name'));
        $this->assertContains('replace_image', array_column($toolDefinitions, 'name'));
        $this->assertContains('create_pdf', array_column($toolDefinitions, 'name'));
        $this->assertContains('replace_pdf', array_column($toolDefinitions, 'name'));
        $this->assertContains('create_external_share', array_column($toolDefinitions, 'name'));
        $this->assertContains('update_description', array_column($toolDefinitions, 'name'));
        $read = collect($toolDefinitions)->firstWhere('name', 'read');
        $this->assertIsArray($read);
        $this->assertSame(
            ['content', 'provenance'],
            data_get($read, 'inputSchema.properties.include.items.enum'),
        );
        $requiredReadArguments = data_get($read, 'inputSchema.required');
        $this->assertIsArray($requiredReadArguments);
        $this->assertContains('page_uid', $requiredReadArguments);
        $this->assertNotContains('include', $requiredReadArguments);
        $search = collect($toolDefinitions)->firstWhere('name', 'search');
        $this->assertIsArray($search);
        $this->assertNull(data_get($search, 'inputSchema.properties.tag_uids.maxItems'));
        $this->assertSame(
            [
                PageType::Markdown->value,
                PageType::HtmlArtifact->value,
                PageType::Image->value,
                PageType::Pdf->value,
            ],
            data_get($search, 'inputSchema.properties.type.enum'),
        );
        $createExternalShare = collect($toolDefinitions)->firstWhere('name', 'create_external_share');
        $this->assertIsArray($createExternalShare);
        $this->assertSame(
            ['expires_at', 'one_time'],
            data_get($createExternalShare, 'inputSchema.properties.mode.enum'),
        );
        $requiredExternalShareArguments = data_get($createExternalShare, 'inputSchema.required');
        $this->assertIsArray($requiredExternalShareArguments);
        $this->assertContains('page_uid', $requiredExternalShareArguments);
        $this->assertContains('mode', $requiredExternalShareArguments);
        $create = collect($toolDefinitions)->firstWhere('name', 'create');
        $this->assertIsArray($create);
        $createContentSummary = data_get($create, 'inputSchema.properties.content.description');
        $this->assertIsString($createContentSummary);
        $this->assertStringContainsString('self-contained HTML', $createContentSummary);
        $this->assertStringContainsString('CDNs', $createContentSummary);
        $this->assertStringContainsString('fetch', $createContentSummary);
        $requiredCreateArguments = data_get($create, 'inputSchema.required');
        $this->assertIsArray($requiredCreateArguments);
        $this->assertContains('change_summary', $requiredCreateArguments);
        $createProperties = data_get($create, 'inputSchema.properties');
        $this->assertIsArray($createProperties);
        $this->assertArrayHasKey('parent_page_uid', $createProperties);
        $organize = collect($toolDefinitions)->firstWhere('name', 'organize');
        $this->assertIsArray($organize);
        $this->assertSame(
            ['string', 'null'],
            data_get($organize, 'inputSchema.properties.parent_page_uid.type'),
        );
        $requiredOrganizeArguments = data_get($organize, 'inputSchema.required');
        $this->assertIsArray($requiredOrganizeArguments);
        $this->assertContains('page_uid', $requiredOrganizeArguments);
        $this->assertContains('expected_metadata_revision', $requiredOrganizeArguments);
        $createImage = collect($toolDefinitions)->firstWhere('name', 'create_image');
        $this->assertIsArray($createImage);
        $this->assertSame(
            ['image/png', 'image/jpeg'],
            data_get($createImage, 'inputSchema.properties.media_type.enum'),
        );
        $requiredCreateImageArguments = data_get($createImage, 'inputSchema.required');
        $this->assertIsArray($requiredCreateImageArguments);
        $this->assertContains('image_base64', $requiredCreateImageArguments);
        $this->assertContains('change_summary', $requiredCreateImageArguments);
        $replaceImage = collect($toolDefinitions)->firstWhere('name', 'replace_image');
        $this->assertIsArray($replaceImage);
        $requiredReplaceImageArguments = data_get($replaceImage, 'inputSchema.required');
        $this->assertIsArray($requiredReplaceImageArguments);
        $this->assertContains('base_version_uid', $requiredReplaceImageArguments);
        $this->assertContains('image_base64', $requiredReplaceImageArguments);
        $createPdf = collect($toolDefinitions)->firstWhere('name', 'create_pdf');
        $this->assertIsArray($createPdf);
        $requiredCreatePdfArguments = data_get($createPdf, 'inputSchema.required');
        $this->assertIsArray($requiredCreatePdfArguments);
        $this->assertContains('pdf_base64', $requiredCreatePdfArguments);
        $this->assertContains('change_summary', $requiredCreatePdfArguments);
        $replacePdf = collect($toolDefinitions)->firstWhere('name', 'replace_pdf');
        $this->assertIsArray($replacePdf);
        $requiredReplacePdfArguments = data_get($replacePdf, 'inputSchema.required');
        $this->assertIsArray($requiredReplacePdfArguments);
        $this->assertContains('base_version_uid', $requiredReplacePdfArguments);
        $this->assertContains('pdf_base64', $requiredReplacePdfArguments);
        $update = collect($toolDefinitions)->firstWhere('name', 'update');
        $this->assertIsArray($update);
        $updateContentSummary = data_get($update, 'inputSchema.properties.content.description');
        $this->assertIsString($updateContentSummary);
        $this->assertStringContainsString('self-contained HTML', $updateContentSummary);
        $this->assertStringContainsString('CDNs', $updateContentSummary);
        $this->assertStringContainsString('fetch', $updateContentSummary);
        $requiredUpdateArguments = data_get($update, 'inputSchema.required');
        $this->assertIsArray($requiredUpdateArguments);
        $this->assertContains('change_summary', $requiredUpdateArguments);
        $revert = collect($toolDefinitions)->firstWhere('name', 'revert');
        $this->assertIsArray($revert);
        $requiredRevertArguments = data_get($revert, 'inputSchema.required');
        $this->assertIsArray($requiredRevertArguments);
        $this->assertContains('change_summary', $requiredRevertArguments);
        $updateDescription = collect($toolDefinitions)->firstWhere('name', 'update_description');
        $this->assertIsArray($updateDescription);
        $updateDescriptionSummary = $updateDescription['description'] ?? null;
        $this->assertIsString($updateDescriptionSummary);
        $this->assertStringContainsString(
            'not OCR-indexed',
            $updateDescriptionSummary,
        );
        $descriptionPropertySummary = data_get($updateDescription, 'inputSchema.properties.description.description');
        $this->assertIsString($descriptionPropertySummary);
        $this->assertStringContainsString(
            'visible content',
            $descriptionPropertySummary,
        );
        $versionPropertySummary = data_get(
            $updateDescription,
            'inputSchema.properties.expected_current_version_uid.description',
        );
        $this->assertIsString($versionPropertySummary);
        $this->assertStringContainsString('current_version_uid', $versionPropertySummary);
        $requiredUpdateDescriptionArguments = data_get($updateDescription, 'inputSchema.required');
        $this->assertIsArray($requiredUpdateDescriptionArguments);
        $this->assertContains('expected_current_version_uid', $requiredUpdateDescriptionArguments);

        $this->assertSame(-32600, $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'missing-method',
        ]))['code']);
        $this->assertSame(-32601, $this->jsonRpcErrorPayload($this->postJsonRpc($token, 'unknown/method'))['code']);
        $this->assertSame(-32602, $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'bad-params',
            'method' => 'tools/call',
            'params' => 'not-an-object',
        ]))['code']);
        $this->assertSame(-32602, $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'scalar-arguments',
            'method' => 'tools/call',
            'params' => [
                'name' => 'search',
                'arguments' => 'not-an-object',
            ],
        ]))['code']);

        $unknownTool = $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'unknown-tool',
            'method' => 'tools/call',
            'params' => [
                'name' => 'missing-tool',
                'arguments' => [],
            ],
        ]));
        // laravel/mcp 0.9.1 rejects list-shaped arguments at the protocol layer
        // (-32602) instead of letting them reach tool-level validation.
        $badArguments = $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'bad-arguments',
            'method' => 'tools/call',
            'params' => [
                'name' => 'search',
                'arguments' => ['not-an-object'],
            ],
        ]));
        $missingToolName = $this->jsonRpcErrorPayload($this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'missing-tool-name',
            'method' => 'tools/call',
            'params' => [
                'arguments' => [],
            ],
        ]));

        $this->assertSame(-32602, $unknownTool['code']);
        $this->assertSame(-32602, $badArguments['code']);
        $this->assertSame(-32602, $missingToolName['code']);
    }

    public function test_read_and_update_error_branches_preserve_boundaries(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Error Owner', 'error-coverage-owner@example.test');
        $service = $this->createServiceAccount('Error Agent', 'error-coverage-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Error Coverage Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $markdownPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Read Missing Content',
            content: '# Before missing',
        );
        $htmlPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'HTML Warning Target',
            content: '<!doctype html><html><body>Safe</body></html>',
            type: PageType::HtmlArtifact,
        );
        $readOnlyToken = $this->issueToken($service, ['mcp:read'])->plainTextToken;
        $updateOnlyToken = $this->issueToken($service, ['mcp:update'])->plainTextToken;

        $version = $markdownPage->currentVersion;
        $this->assertInstanceOf(PageVersion::class, $version);
        Storage::disk('artifacts')->delete($version->content_storage_path);

        $this->assertSame('content_unavailable', $this->toolErrorPayload($this->callTool($readOnlyToken, 'read', [
            'page_uid' => $markdownPage->uid,
        ]))['type']);
        $this->assertSame('insufficient_scope', $this->toolErrorPayload($this->callTool($readOnlyToken, 'update', [
            'page_uid' => $markdownPage->uid,
            'content' => '# Not allowed',
            'base_version_uid' => $markdownPage->current_version_uid,
            'change_summary' => 'Attempt an update without scope.',
        ]))['type']);
        $warningUpdate = $this->successfulToolPayload($this->callTool($updateOnlyToken, 'update', [
            'page_uid' => $htmlPage->uid,
            'content' => '<!doctype html><html><body><script>alert(1)</script></body></html>',
            'base_version_uid' => $htmlPage->current_version_uid,
            'change_summary' => 'Add the scripted warning fixture.',
        ]));
        $warningVersion = PageVersion::query()->whereKey($this->payloadString($warningUpdate, 'version_uid'))->sole();

        $this->assertSame('warnings', $warningVersion->scan_status->value);
        $this->assertSame('inline_script', $warningVersion->scan_findings[0]['code'] ?? null);
        $this->assertSame('blocked_content', $this->toolErrorPayload($this->callTool($updateOnlyToken, 'update', [
            'page_uid' => $htmlPage->uid,
            'content' => '<!doctype html><html><body>AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz1234567890</body></html>',
            'base_version_uid' => $warningVersion->uid,
            'change_summary' => 'Attempt the blocked secret fixture.',
        ]))['type']);
    }

    public function test_console_token_command_rejects_admin_service_accounts_without_downgrading_membership(): void
    {
        $owner = $this->createUser('Command Owner', 'command-owner@example.test');
        $service = $this->createServiceAccount('Command Agent', 'command-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Command Team');
        $this->addMember($workspace, $service, WorkspaceRole::Admin);

        $exitCode = Artisan::call('artifactflow:mcp-token-create', [
            '--email' => $service->email,
            '--workspace' => [$workspace->uid],
            '--scope' => ['mcp:search'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'MCP service accounts must not hold workspace Admin memberships.',
            Artisan::output(),
        );
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $service->uid)
            ->sole();

        $this->assertSame(WorkspaceRole::Admin, $membership->role);
        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_mcp_admin_authority_is_downscoped_for_object_checks_search_and_snippets(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('MCP Owner', 'mcp-owner@example.test');
        $admin = $this->enableTwoFactor($this->createUser('MCP Admin', 'mcp-admin@example.test'));
        $sharingEditor = $this->enableTwoFactor($this->createUser(
            'MCP Sharing Editor',
            'mcp-sharing-editor@example.test',
        ));
        $grantedUser = $this->enableTwoFactor($this->createUser('Granted User', 'mcp-granted@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'MCP Admin Team');
        $workspace->forceFill(['allow_editor_page_sharing' => true])->save();
        $this->addMember($workspace, $admin, WorkspaceRole::Admin);
        $this->addMember($workspace, $sharingEditor, WorkspaceRole::Editor);

        $inheritedPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Visible Needle',
            content: '# Visible Needle',
            description: 'Visible Needle summary.',
        );
        $restrictedPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Restricted Needle',
            content: '# Restricted Needle',
            description: 'Restricted Needle summary.',
        );
        $restrictedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $unapprovedPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Draft Needle',
            description: 'Draft Needle summary.',
            content: '# Draft Needle',
        ));

        $otherOwner = $this->createUser('Other Owner', 'mcp-other-owner@example.test');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherOwner, 'MCP Other Team');
        // The granted user stays outside the page workspace, so the per-page
        // grant (not inheritance) is what confers access under MCP downscoping.
        $grantSharedWorkspace = app(CreateSharedWorkspace::class)->handle($otherOwner, 'MCP Grant Shared');
        $this->addMember($grantSharedWorkspace, $grantedUser, WorkspaceRole::Reader);
        $crossTenantPage = $this->createPageWithApprovedStatus(
            actor: $otherOwner,
            workspace: $otherWorkspace,
            title: 'Cross Needle',
            content: '# Cross Needle',
            description: 'Cross Needle summary.',
        );
        $grantedPage = $this->createPageWithApprovedStatus(
            actor: $otherOwner,
            workspace: $otherWorkspace,
            title: 'Granted Needle',
            content: '# Granted Needle',
            description: 'Granted Needle summary.',
        );
        $grantedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $this->grantUserPageAccess($grantedPage, $grantedUser, $otherOwner, WorkspaceRole::Admin);

        $adminToken = $this->issueToken($admin, [
            McpAccessTokenIssuer::SCOPE_SEARCH,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ]);
        $sharingEditorToken = $this->issueToken($sharingEditor, [
            McpAccessTokenIssuer::SCOPE_SEARCH,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ]);
        $grantedToken = $this->issueToken($grantedUser, [
            McpAccessTokenIssuer::SCOPE_SEARCH,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ]);

        $this->withMcpContext($adminToken->accessToken, function () use ($admin, $inheritedPage, $restrictedPage): void {
            $access = app(PageAccess::class);

            $this->assertTrue($access->canView($admin, $inheritedPage));
            $this->assertTrue($access->canEdit($admin, $inheritedPage));
            $this->assertFalse($access->canView($admin, $restrictedPage));
            $this->assertFalse($access->canEdit($admin, $restrictedPage));
            $this->assertFalse($access->canManageAccess($admin, $inheritedPage));
            $this->assertFalse($access->canHardDelete($admin, $inheritedPage));
            $this->assertFalse($access->canArchive($admin, $inheritedPage));
            $this->assertFalse($access->canChangeAccessMode($admin, $inheritedPage));
            $this->assertFalse($access->canTransferOwnership($admin, $inheritedPage));
        });

        $this->withMcpContext($sharingEditorToken->accessToken, function () use ($sharingEditor, $inheritedPage): void {
            $access = app(PageAccess::class);

            $this->assertTrue($access->canView($sharingEditor, $inheritedPage));
            $this->assertTrue($access->canEdit($sharingEditor, $inheritedPage));
            $this->assertFalse($access->canManageAccess($sharingEditor, $inheritedPage));
            $this->assertFalse($access->canHardDelete($sharingEditor, $inheritedPage));
        });

        $this->withMcpContext($grantedToken->accessToken, function () use ($grantedUser, $grantedPage): void {
            $access = app(PageAccess::class);

            $this->assertTrue($access->canView($grantedUser, $grantedPage));
            $this->assertTrue($access->canEdit($grantedUser, $grantedPage));
            $this->assertFalse($access->canManageAccess($grantedUser, $grantedPage));
            $this->assertFalse($access->canHardDelete($grantedUser, $grantedPage));
        });

        $adminSearch = $this->successfulToolPayload($this->callTool($adminToken->plainTextToken, 'search', [
            'query' => 'Needle',
            'include_snippet' => true,
        ]));
        $adminResults = $this->payloadList($adminSearch, 'results');

        $this->assertEqualsCanonicalizing(
            [$inheritedPage->uid, $unapprovedPage->uid],
            array_column($adminResults, 'uid'),
        );
        $this->assertStringNotContainsString(
            $restrictedPage->title,
            json_encode($adminResults, JSON_THROW_ON_ERROR),
        );
        $this->assertStringContainsString(
            $unapprovedPage->title,
            json_encode($adminResults, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            $crossTenantPage->title,
            json_encode($adminResults, JSON_THROW_ON_ERROR),
        );

        $this->assertSame(
            ['type' => 'not_found', 'message' => 'Page not found.'],
            $this->toolErrorPayload($this->callTool($adminToken->plainTextToken, 'read', [
                'page_uid' => $restrictedPage->uid,
            ])),
        );

        $grantedRead = $this->successfulToolPayload($this->callTool($grantedToken->plainTextToken, 'read', [
            'page_uid' => $grantedPage->uid,
        ]));
        $this->assertSame($grantedPage->uid, $grantedRead['uid']);

        $this->withMcpContext($grantedToken->accessToken, function () use ($grantedUser, $grantedPage): void {
            $results = app(PageSearch::class)->search(
                actor: $grantedUser,
                filters: new PageSearchFilters(
                    query: 'Needle',
                    workspaceUid: null,
                    type: null,
                    statuses: PageSearchFilters::activeStatuses(),
                    categoryUids: [],
                    tagUids: [],
                    ownerUserUid: null,
                    sort: \App\Application\PageCatalog\PageSearchSort::Relevance,
                ),
                includeSnippets: true,
            );

            $grantedResult = array_values(array_filter(
                $results,
                static fn ($result): bool => $result->page->uid === $grantedPage->uid,
            ))[0] ?? null;

            $this->assertNotNull($grantedResult);
            $this->assertNull($grantedResult->workspaceName);
        });
    }

    public function test_system_admin_flag_does_not_grant_page_access_in_browser_or_mcp(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('MCP Grant Admin', 'mcp-grant-admin@example.test', isSystemAdmin: true);
        $owner = $this->createUser('Grant Page Owner', 'mcp-grant-owner@example.test');

        $ownerWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Grant Owner Team');
        $page = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $ownerWorkspace,
            title: 'Grant Alignment Page',
            content: '# Grant Alignment Page',
        );

        $grants = app(GrantPageAccess::class);
        $access = app(PageAccess::class);

        $this->assertFalse($access->canView($admin, $page));
        $this->assertFalse($access->canManageAccess($admin, $page));

        try {
            $grants->handle($admin, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $admin->uid,
                role: WorkspaceRole::Reader,
            ));
            $this->fail('Expected the System Admin flag not to grant browser page-management authority.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You cannot grant access to this page.', $exception->getMessage());
        }

        $this->assertSame(0, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->count());

        $adminToken = $this->issueToken($admin, [
            McpAccessTokenIssuer::SCOPE_SEARCH,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ]);

        $this->withMcpContext($adminToken->accessToken, function () use ($grants, $access, $admin, $page): void {
            $this->assertFalse($access->canView($admin, $page));
            $this->assertFalse($access->canManageAccess($admin, $page));

            try {
                $grants->handle($admin, new GrantPageAccessCommand(
                    pageUid: $page->uid,
                    subjectType: PageAccessSubjectType::User,
                    subjectUid: $admin->uid,
                    role: WorkspaceRole::Reader,
                ));
                $this->fail('Expected the System Admin flag not to grant MCP page-management authority.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('You cannot grant access to this page.', $exception->getMessage());
            }
        });

        $this->assertSame(0, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->count());
    }

    public function test_mcp_system_admin_token_authenticates_without_global_visibility(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('System Target Owner', 'sys-target-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'System Target Team');
        $page = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'System Needle',
            content: '# System Needle',
        );
        $systemAdmin = $this->createUser('System MCP Admin', 'sys-mcp-admin@example.test', true);
        $token = $this->issueToken($systemAdmin, [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_READ]);

        $this->postJsonRpc($token->plainTextToken, 'tools/list')->assertOk();

        $search = $this->successfulToolPayload($this->callTool($token->plainTextToken, 'search', [
            'query' => 'System Needle',
            'include_snippet' => true,
        ]));

        $this->assertSame([], $this->payloadList($search, 'results'));
        $this->assertSame(
            ['type' => 'not_found', 'message' => 'Page not found.'],
            $this->toolErrorPayload($this->callTool($token->plainTextToken, 'read', [
                'page_uid' => $page->uid,
            ])),
        );
    }

    public function test_mcp_membership_reach_follows_current_workspace_memberships(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Membership Owner', 'mcp-membership-owner@example.test');
        $principal = $this->enableTwoFactor($this->createUser('Membership Principal', 'mcp-membership-principal@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Membership Team');
        $page = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Membership Needle',
            content: '# Membership Needle',
        );
        $token = $this->issueToken($principal, [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_READ]);

        $this->assertSame([], $this->payloadList($this->successfulToolPayload($this->callTool(
            $token->plainTextToken,
            'search',
            ['query' => 'Membership Needle'],
        )), 'results'));

        $this->addMember($workspace, $principal, WorkspaceRole::Editor);
        $visible = $this->payloadList($this->successfulToolPayload($this->callTool(
            $token->plainTextToken,
            'search',
            ['query' => 'Membership Needle'],
        )), 'results');
        $this->assertSame([$page->uid], array_column($visible, 'uid'));

        WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $principal->uid)
            ->delete();
        app(PageAccess::class)->flushCache();

        $this->assertSame([], $this->payloadList($this->successfulToolPayload($this->callTool(
            $token->plainTextToken,
            'search',
            ['query' => 'Membership Needle'],
        )), 'results'));
    }

    public function test_workspace_scoped_tokens_constrain_discovery_search_read_and_write(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Scoped Owner', 'scoped-owner@example.test');
        $service = $this->createServiceAccount('Scoped Agent', 'scoped-agent@example.test');
        $alphaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Alpha Team');
        $betaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Beta Team');
        $this->addMember($alphaWorkspace, $service, WorkspaceRole::Editor);
        $this->addMember($betaWorkspace, $service, WorkspaceRole::Editor);
        $alphaPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $alphaWorkspace,
            title: 'Scoped Alpha Needle',
            content: '# Scoped Alpha Needle',
        );
        $betaPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $betaWorkspace,
            title: 'Scoped Beta Needle',
            content: '# Scoped Beta Needle',
        );
        $token = $this->issueToken(
            principal: $service,
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_READ, McpAccessTokenIssuer::SCOPE_UPDATE],
            workspaceUids: [$alphaWorkspace->uid],
        )->plainTextToken;

        $workspaces = $this->payloadList(
            $this->successfulToolPayload($this->callTool($token, 'list_workspaces')),
            'workspaces',
        );
        $this->assertSame([$alphaWorkspace->uid], array_column($workspaces, 'uid'));
        $this->assertStringNotContainsString(
            $betaWorkspace->name,
            json_encode($workspaces, JSON_THROW_ON_ERROR),
        );

        $unfilteredSearch = $this->payloadList($this->successfulToolPayload($this->callTool($token, 'search', [
            'query' => 'Scoped',
        ])), 'results');
        $alphaFilterSearch = $this->payloadList($this->successfulToolPayload($this->callTool($token, 'search', [
            'query' => 'Scoped',
            'workspace_uid' => $alphaWorkspace->uid,
        ])), 'results');
        $betaFilterSearch = $this->payloadList($this->successfulToolPayload($this->callTool($token, 'search', [
            'query' => 'Scoped',
            'workspace_uid' => $betaWorkspace->uid,
        ])), 'results');

        $this->assertSame([$alphaPage->uid], array_column($unfilteredSearch, 'uid'));
        $this->assertSame([$alphaPage->uid], array_column($alphaFilterSearch, 'uid'));
        $this->assertSame([], $betaFilterSearch);
        $this->assertSame($alphaPage->uid, $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $alphaPage->uid,
        ]))['uid']);
        $this->assertSame(['type' => 'not_found', 'message' => 'Page not found.'], $this->toolErrorPayload(
            $this->callTool($token, 'read', ['page_uid' => $betaPage->uid]),
        ));
        $this->assertSame(['type' => 'not_found', 'message' => 'Page not found.'], $this->toolErrorPayload(
            $this->callTool($token, 'update', [
                'page_uid' => $betaPage->uid,
                'content' => '# blocked by workspace scope',
                'base_version_uid' => $betaPage->current_version_uid,
                'change_summary' => 'Attempt an out-of-scope update.',
            ]),
        ));
    }
}
