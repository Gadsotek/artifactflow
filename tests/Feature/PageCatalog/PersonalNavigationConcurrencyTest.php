<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\PersonalPageState;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalNavigationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Independent sessions need committed fixtures. */
    protected array $connectionsToTransact = [];

    protected function afterRefreshingDatabase(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        });
    }

    /** @return array<string, array{bool}> */
    public static function operations(): array
    {
        return ['ordinary visit' => [false], 'explicit favorite mutation' => [true]];
    }

    #[DataProvider('operations')]
    public function test_only_explicit_favorite_mutations_block_concurrent_page_edits(bool $favorite): void
    {
        $user = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Concurrent navigation');
        $page = Page::factory()->create(['workspace_uid' => $workspace->uid, 'owner_user_uid' => $user->uid]);
        config(['database.connections.navigation_concurrent' => DB::connection()->getConfig()]);
        $concurrent = DB::connection('navigation_concurrent');
        $concurrent->statement("SET lock_timeout TO '200ms'");
        $blocked = false;

        try {
            DB::transaction(function () use ($user, $page, $favorite, $concurrent, &$blocked): void {
                $state = app(PersonalPageState::class);
                if ($favorite) {
                    $state->favorite($user, $page, true);
                } else {
                    $state->recordVisit($user, $page);
                }

                // Keep the navigation transaction open while a separate session
                // changes page metadata. A visit's FK key-share lock is compatible
                // with this edit; an exclusive page lock is not.
                try {
                    $this->assertSame(1, $concurrent->table('pages')->where('uid', $page->uid)->update(['description' => 'Concurrent edit']));
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', (string) $exception->getCode());
                    $blocked = true;
                }
            });
        } finally {
            DB::purge('navigation_concurrent');
        }

        $this->assertSame($favorite, $blocked);
        $this->assertDatabaseHas('personal_page_states', ['user_uid' => $user->uid, 'page_uid' => $page->uid]);
    }
}
