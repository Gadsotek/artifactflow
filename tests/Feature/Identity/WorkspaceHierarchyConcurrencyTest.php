<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\RegisterWorkspaceInvitationUser;
use App\Application\Identity\RegisterWorkspaceInvitationUserCommand;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Application\Identity\WorkspaceHierarchyGraph;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

final class WorkspaceHierarchyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Forked sessions must see committed fixtures rather than an outer test transaction.
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

    public function test_page_creation_waits_for_reparenting_and_reauthorizes_against_the_committed_tree(): void
    {
        Storage::fake('artifacts');
        $admin = $this->createUser('Concurrent Admin', 'hierarchy-concurrent-admin@example.test');
        $oldMember = $this->createUser('Concurrent Old Member', 'hierarchy-concurrent-member@example.test');
        $oldRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Concurrent Old Root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Concurrent New Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Concurrent Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true));
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $oldRoot->uid,
            'user_uid' => $oldMember->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $connectionName = $this->configureConcurrentConnection('hierarchy_page_create_concurrent');
        [$parentSocket, $childSocket] = $this->socketPair();

        DB::beginTransaction();
        app(WorkspaceHierarchyGraph::class)->acquireMutationLock();
        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::rollBack();
            fclose($parentSocket);
            fclose($childSocket);
            self::fail('Unable to fork the concurrent page creation.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            DB::setDefaultConnection($connectionName);

            try {
                $member = User::query()->findOrFail($oldMember->uid);
                $access = app(PageAccess::class);

                if (!$access->canCreateInWorkspace($member, $child->uid)) {
                    fwrite($childSocket, "error:missing-preauthorization\n");
                    fclose($childSocket);
                    exit(1);
                }

                fwrite($childSocket, "preauthorized\n");
                fflush($childSocket);
                app(CreatePage::class)->handle($member, new CreatePageCommand(
                    workspaceUid: $child->uid,
                    type: PageType::Markdown,
                    title: 'Concurrent stale placement',
                    description: null,
                    content: '# Concurrent stale placement',
                ));
                fwrite($childSocket, "error:created\n");
                fclose($childSocket);
                exit(1);
            } catch (AuthorizationException) {
                fwrite($childSocket, "denied\n");
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($childSocket, 'error:' . get_debug_type($exception) . ':' . (string) $exception->getCode() . "\n");
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        $signal = fgets($parentSocket);
        $this->assertSame("preauthorized\n", $signal);

        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $newRoot->uid, true));
        DB::commit();

        $outcome = fgets($parentSocket);
        fclose($parentSocket);
        $this->assertSame("denied\n", $outcome);
        $this->assertChildExitedSuccessfully($pid);
        $this->assertDatabaseMissing('pages', ['title' => 'Concurrent stale placement']);
        DB::purge($connectionName);
    }

    public function test_registration_waits_on_hierarchy_before_workspace_locks_while_reparenting(): void
    {
        $admin = $this->createUser('Registration Admin', 'hierarchy-registration-admin@example.test');
        $oldRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Registration Old Root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($admin, 'Registration New Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Registration Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true));
        $invitation = app(InviteUserToWorkspace::class)->handle(
            $admin,
            new InviteUserToWorkspaceCommand(
                workspaceUid: $child->uid,
                email: 'hierarchy-concurrent-registrant@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $presentedToken = $invitation->plainToken;
        $this->assertIsString($presentedToken);
        $connectionName = $this->configureConcurrentConnection('hierarchy_registration_concurrent');
        [$parentSocket, $childSocket] = $this->socketPair();

        DB::beginTransaction();
        app(WorkspaceHierarchyGraph::class)->acquireMutationLock();
        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::rollBack();
            fclose($parentSocket);
            fclose($childSocket);
            self::fail('Unable to fork concurrent invitation registration.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            DB::setDefaultConnection($connectionName);

            try {
                fwrite($childSocket, "starting\n");
                fflush($childSocket);
                $result = app(RegisterWorkspaceInvitationUser::class)->handle(
                    new RegisterWorkspaceInvitationUserCommand(
                        invitationUid: $invitation->uid,
                        presentedToken: $presentedToken,
                        name: 'Concurrent Registrant',
                        password: 'a-strong-password-123',
                    ),
                );
                fwrite($childSocket, $result->membership->workspace_uid === $child->uid ? "joined\n" : "error:workspace\n");
                fclose($childSocket);
                exit($result->membership->workspace_uid === $child->uid ? 0 : 1);
            } catch (Throwable $exception) {
                fwrite($childSocket, 'error:' . get_debug_type($exception) . ':' . (string) $exception->getCode() . "\n");
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        $this->assertSame("starting\n", fgets($parentSocket));
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $newRoot->uid, true));
        DB::commit();

        $this->assertSame("joined\n", fgets($parentSocket));
        fclose($parentSocket);
        $this->assertChildExitedSuccessfully($pid);
        $this->assertDatabaseHas('users', ['email' => 'hierarchy-concurrent-registrant@example.test']);
        DB::purge($connectionName);
    }

    private function configureConcurrentConnection(string $name): string
    {
        $defaultConnection = DB::getDefaultConnection();
        $connection = config("database.connections.{$defaultConnection}");
        $this->assertIsArray($connection);
        config(["database.connections.{$name}" => $connection]);

        return $name;
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            self::fail('Unable to create a hierarchy concurrency synchronization socket pair.');
        }

        stream_set_timeout($sockets[0], 10);
        stream_set_timeout($sockets[1], 10);

        return [$sockets[0], $sockets[1]];
    }

    private function assertChildExitedSuccessfully(int $pid): void
    {
        $status = 0;
        $waitedPid = pcntl_waitpid($pid, $status);
        $this->assertSame($pid, $waitedPid);

        if (!is_int($status)) {
            self::fail('The hierarchy concurrency child did not return an integer wait status.');
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
