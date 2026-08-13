<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpAccessTokenRevoker;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\ExternalShare;
use App\Models\InstallationSettings;
use App\Models\McpAccessToken;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

final class McpTokenRevocationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The forked revocation session must see committed fixtures.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    protected function afterRefreshingDatabase(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        });
    }

    public function test_revocation_rejects_an_mcp_mutation_authenticated_from_a_stale_token_snapshot(): void
    {
        Storage::fake('artifacts');
        $this->enableExternalSharing();

        $admin = app(CreateUser::class)->handle(
            'HTTP Race Admin',
            'http-race-admin@example.test',
            'correct horse battery staple',
        );
        $service = app(CreateUser::class)->handle(
            'HTTP Race Agent',
            'http-race-agent@example.test',
            'correct horse battery staple',
        );
        $service->forceFill(['is_service_account' => true])->save();
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'HTTP Race Team');
        $workspace->forceFill(['allow_editor_page_sharing' => true])->save();
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $service->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($service, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'HTTP revocation race target',
            description: null,
            content: '# HTTP revocation race target',
        ));
        $issuedToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $service,
            name: 'HTTP revocation race token',
            scopes: [McpAccessTokenIssuer::SCOPE_SHARE],
            expiresAt: now()->addHour(),
            workspaceUids: [$workspace->uid],
        );

        $defaultConnection = DB::getDefaultConnection();
        $connection = config("database.connections.{$defaultConnection}");
        $this->assertIsArray($connection);
        config(['database.connections.mcp_revocation_competing' => $connection]);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            self::fail('Unable to create MCP race synchronization sockets.');
        }

        [$parentSocket, $childSocket] = $sockets;
        stream_set_timeout($parentSocket, 10);
        stream_set_timeout($childSocket, 10);
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            self::fail('Unable to fork the MCP revocation transaction.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            DB::setDefaultConnection('mcp_revocation_competing');

            try {
                if (fgets($childSocket) !== "revoke\n") {
                    fwrite($childSocket, "error:missing-signal\n");
                    fclose($childSocket);
                    exit(1);
                }

                $freshToken = McpAccessToken::query()->findOrFail($issuedToken->accessToken->uid);
                $freshAdmin = $admin->fresh();
                app(McpAccessTokenRevoker::class)->revoke($freshToken, $freshAdmin);
                fwrite($childSocket, "revoked\n");
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                fwrite(
                    $childSocket,
                    sprintf("error:%s:%s\n", get_debug_type($exception), (string) $exception->getCode()),
                );
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        $barrierTriggered = false;
        $revocationOutcome = null;
        $tokenHash = McpAccessTokenIssuer::hashToken($issuedToken->plainTextToken);

        DB::listen(function (QueryExecuted $query) use (
            &$barrierTriggered,
            &$revocationOutcome,
            $parentSocket,
            $tokenHash,
        ): void {
            $sql = strtolower($query->sql);

            if (
                $barrierTriggered
                || !str_starts_with($sql, 'select * from "mcp_access_tokens"')
                || !in_array($tokenHash, $query->bindings, true)
            ) {
                return;
            }

            $barrierTriggered = true;
            fwrite($parentSocket, "revoke\n");
            fflush($parentSocket);
            $outcome = fgets($parentSocket);
            $revocationOutcome = is_string($outcome) ? trim($outcome) : null;
        });

        $waitedPid = -1;
        $status = 0;

        try {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $issuedToken->plainTextToken,
                'MCP-Session-Id' => 'deterministic-http-revocation-race',
            ])->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 'post-revocation-share',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'create_external_share',
                    'arguments' => [
                        'page_uid' => $page->uid,
                        'mode' => 'one_time',
                    ],
                ],
            ]);
        } finally {
            fclose($parentSocket);
            $waitedPid = pcntl_waitpid($pid, $status);
        }

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $text = $response->json('result.content.0.text');
        $this->assertIsString($text);
        $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['error'] ?? null);
        $this->assertSame('authentication_required', $payload['error']['type'] ?? null);
        $this->assertTrue($barrierTriggered);
        $this->assertSame('revoked', $revocationOutcome);
        $this->assertSame($pid, $waitedPid);
        $this->assertIsInt($status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertNotNull($issuedToken->accessToken->refresh()->revoked_at);
        $this->assertSame(0, ExternalShare::query()->where('page_uid', $page->uid)->count());
    }

    private function enableExternalSharing(): void
    {
        $values = app(InstallationLimitSettings::class)->current();

        InstallationSettings::query()->forceCreate(array_merge($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => true,
            'external_share_acknowledgement_required' => true,
            'external_share_max_expiry_hours' => 168,
        ]));
    }
}
