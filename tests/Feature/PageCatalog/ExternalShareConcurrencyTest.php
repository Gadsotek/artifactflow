<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\Identity\UpdateWorkspaceSettings;
use App\Application\Identity\UpdateWorkspaceSettingsCommand;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\ExternalShare;
use App\Models\InstallationSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

final class ExternalShareConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The forked database session must see committed fixtures, so this one
     * concurrency test cannot run inside RefreshDatabase's outer transaction.
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

    public function test_workspace_sharing_cannot_be_disabled_between_mcp_authorization_and_share_commit(): void
    {
        Storage::fake('artifacts');
        $this->enableExternalSharing();

        $admin = app(CreateUser::class)->handle(
            'External Share Race Admin',
            'external-share-race-admin@example.test',
            'correct horse battery staple',
        );
        $service = app(CreateUser::class)->handle(
            'External Share Race Agent',
            'external-share-race-agent@example.test',
            'correct horse battery staple',
        );
        $service->forceFill(['is_service_account' => true])->save();
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'External Share Race Team');
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
            title: 'External share race target',
            description: null,
            content: '# External share race target',
        ));
        $token = app(McpAccessTokenIssuer::class)->issue(
            principal: $service,
            name: 'External share race token',
            scopes: [McpAccessTokenIssuer::SCOPE_SHARE],
            expiresAt: now()->addHour(),
            workspaceUids: [$workspace->uid],
        );
        $defaultConnection = DB::getDefaultConnection();
        $connection = config("database.connections.{$defaultConnection}");
        $this->assertIsArray($connection);
        config(['database.connections.external_share_concurrent' => $connection]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            self::fail('Unable to create the concurrency synchronization socket pair.');
        }

        [$parentSocket, $childSocket] = $sockets;
        stream_set_timeout($parentSocket, 10);
        stream_set_timeout($childSocket, 10);
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            self::fail('Unable to fork the concurrent workspace-settings transaction.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            DB::setDefaultConnection('external_share_concurrent');

            try {
                $signal = fgets($childSocket);

                if ($signal !== "disable\n") {
                    fwrite($childSocket, "error:missing-signal\n");
                    fclose($childSocket);
                    exit(1);
                }

                DB::statement("SET lock_timeout TO '500ms'");
                $freshAdmin = User::query()->findOrFail($admin->uid);

                app(UpdateWorkspaceSettings::class)->handle(
                    $freshAdmin,
                    new UpdateWorkspaceSettingsCommand(
                        workspaceUid: $workspace->uid,
                        name: $workspace->name,
                        allowEditorInvites: false,
                        allowEditorPageSharing: false,
                    ),
                );
                fwrite($childSocket, "committed\n");
                fclose($childSocket);
                exit(0);
            } catch (QueryException $exception) {
                $outcome = (string) $exception->getCode() === '55P03'
                    ? "blocked\n"
                    : "error:query\n";
                fwrite($childSocket, $outcome);

                if ($outcome === "blocked\n") {
                    fflush($childSocket);
                    $completion = fgets($childSocket);

                    if ($completion !== "complete\n") {
                        fclose($childSocket);
                        exit(1);
                    }
                }

                fclose($childSocket);
                exit($outcome === "blocked\n" ? 0 : 1);
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
        $raceTriggered = false;
        $disableOutcome = null;
        $pageLocked = false;
        $workspaceLockedAfterPage = false;

        DB::listen(function (QueryExecuted $query) use (
            &$raceTriggered,
            &$disableOutcome,
            &$pageLocked,
            &$workspaceLockedAfterPage,
            $parentSocket,
            $page,
            $workspace,
        ): void {
            $sql = strtolower($query->sql);

            if (
                str_contains($sql, 'for update')
                && str_contains($sql, '"pages"')
                && in_array($page->uid, $query->bindings, true)
            ) {
                $pageLocked = true;

                return;
            }

            if (
                $raceTriggered
                || !str_contains($sql, 'select')
                || !str_contains($sql, '"workspaces"')
                || !in_array($workspace->uid, $query->bindings, true)
            ) {
                return;
            }

            $raceTriggered = true;
            $workspaceLockedAfterPage = $pageLocked && str_contains($sql, 'for update');
            fwrite($parentSocket, "disable\n");
            fflush($parentSocket);
            $outcome = fgets($parentSocket);
            $disableOutcome = is_string($outcome) ? trim($outcome) : null;
        });

        $context = app(McpRequestContext::class);
        $context->activate($token->accessToken, 'external-share-race');
        $waitedPid = -1;
        $status = 0;

        try {
            app(CreateExternalShare::class)->handleForMcp(
                $service,
                new CreateExternalShareCommand(
                    pageUid: $page->uid,
                    mode: ExternalShareMode::OneTime,
                    expiresAt: null,
                ),
            );
        } finally {
            $context->clear();

            if ($disableOutcome === 'blocked') {
                fwrite($parentSocket, "complete\n");
                fflush($parentSocket);
            }

            fclose($parentSocket);
            $waitedPid = pcntl_waitpid($pid, $status);
        }

        $this->assertTrue($raceTriggered, 'The workspace authorization read must participate in the race.');
        $this->assertTrue(
            $workspaceLockedAfterPage,
            'External-share creation must lock the page before it locks the workspace sharing policy.',
        );
        $this->assertSame(
            'blocked',
            $disableOutcome,
            'Workspace sharing must not commit as disabled before the already-authorized share transaction commits.',
        );
        $this->assertSame($pid, $waitedPid);

        if (!is_int($status)) {
            self::fail('The child process did not return an integer wait status.');
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(1, ExternalShare::query()->where('page_uid', $page->uid)->count());

        app(UpdateWorkspaceSettings::class)->handle(
            $admin,
            new UpdateWorkspaceSettingsCommand(
                workspaceUid: $workspace->uid,
                name: $workspace->name,
                allowEditorInvites: false,
                allowEditorPageSharing: false,
            ),
        );

        $this->assertFalse(Workspace::query()->findOrFail($workspace->uid)->allow_editor_page_sharing);
    }

    private function enableExternalSharing(): void
    {
        InstallationSettings::query()->forceCreate([
            ...app(InstallationLimitSettings::class)->current()->toPersistenceArray(),
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => true,
            'external_share_acknowledgement_required' => true,
            'external_share_max_expiry_hours' => 168,
        ]);
    }
}
