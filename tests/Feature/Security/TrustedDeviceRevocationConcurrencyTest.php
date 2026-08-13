<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\TrustedDeviceManager;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

final class TrustedDeviceRevocationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

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

    public function test_exact_revocation_invalidates_a_login_that_already_accepted_the_trusted_device(): void
    {
        $rawDeviceToken = 'deterministic-revoked-device-token';
        $user = User::query()->create([
            'name' => 'Trusted Device Race User',
            'email' => 'trusted-device-race@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $user->forceFill([
            'remember_token' => Str::random(60),
            'two_factor_secret' => self::SECRET,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [],
            'two_factor_last_used_timestep' => null,
        ])->save();
        app(CreatePersonalWorkspaceForUser::class)->handle($user);
        $trustedDevice = TrustedDevice::query()->forceCreate([
            'user_uid' => $user->uid,
            'token_hash' => hash('sha256', $rawDeviceToken),
            'label' => 'Stolen test browser',
            'user_agent_summary' => 'Stolen test browser',
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
        ]);
        $originalAuthRevision = $user->auth_revision;

        $defaultConnection = DB::getDefaultConnection();
        $connection = config("database.connections.{$defaultConnection}");
        $this->assertIsArray($connection);
        config(['database.connections.trusted_device_competing' => $connection]);

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            self::fail('Unable to create trusted-device race synchronization sockets.');
        }

        [$parentSocket, $childSocket] = $sockets;
        stream_set_timeout($parentSocket, 10);
        stream_set_timeout($childSocket, 10);
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            self::fail('Unable to fork the trusted-device revocation transaction.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            DB::setDefaultConnection('trusted_device_competing');

            try {
                if (fgets($childSocket) !== "revoke\n") {
                    fwrite($childSocket, "error:missing-signal\n");
                    fclose($childSocket);
                    exit(1);
                }

                $freshUser = User::query()->findOrFail($user->uid);
                $freshDevice = TrustedDevice::query()->findOrFail($trustedDevice->uid);
                app(TrustedDeviceManager::class)->revoke(
                    $freshUser,
                    $freshDevice,
                    $freshUser->auth_revision,
                );
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

        DB::listen(function (QueryExecuted $query) use (
            &$barrierTriggered,
            &$revocationOutcome,
            $parentSocket,
            $trustedDevice,
        ): void {
            $sql = strtolower($query->sql);

            if (
                $barrierTriggered
                || !str_starts_with($sql, 'update "trusted_devices"')
                || !str_contains($sql, '"last_used_at"')
                || !in_array($trustedDevice->uid, $query->bindings, true)
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
            $this->withCookie(TrustedDeviceManager::COOKIE_NAME, $rawDeviceToken)
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'correct horse battery staple',
                ])
                ->assertRedirect('/dashboard');
        } finally {
            fclose($parentSocket);
            $waitedPid = pcntl_waitpid($pid, $status);
        }

        $this->assertTrue($barrierTriggered, 'The revoke must occur after the trusted-device check.');
        $this->assertSame('revoked', $revocationOutcome);
        $this->assertSame($pid, $waitedPid);
        $this->assertIsInt($status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertDatabaseMissing('trusted_devices', ['uid' => $trustedDevice->uid]);
        $this->assertSame($originalAuthRevision + 1, $user->refresh()->auth_revision);
        $this->assertAuthenticatedAs($user);

        // Start the redirected request with a fresh guard instance, as a real
        // request lifecycle does, so the user row is reloaded from PostgreSQL.
        Auth::forgetGuards();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        Auth::forgetGuards();
        $this->withCookie(TrustedDeviceManager::COOKIE_NAME, $rawDeviceToken)
            ->post('/login', [
                'email' => $user->email,
                'password' => 'correct horse battery staple',
            ])
            ->assertRedirect('/login/two-factor-challenge');
        $this->assertGuest();
    }
}
