<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Models\InstallationSettings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class McpPassagePolicyConcurrencyTest extends McpTestCase
{
    /** @var list<string> The second database session must see committed fixtures. */
    protected array $connectionsToTransact = [];

    protected function afterRefreshingDatabase(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        });
    }

    public function test_disable_and_live_tool_admission_serialize_across_database_sessions(): void
    {
        Storage::fake('artifacts');
        $owner = $this->createUser('Owner', 'passage-lock-owner@example.test');
        $reader = $this->createServiceAccount('Reader', 'passage-lock-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Evidence');
        $this->addMember($workspace, $reader, WorkspaceRole::Reader);
        $token = $this->issueToken($reader, ['mcp:read', 'mcp:search'])->plainTextToken;
        $settings = InstallationSettings::query()->forceCreate(app(InstallationLimitSettings::class)->current()->toPersistenceArray() + [
            'passage_retrieval_enabled' => true, 'passage_retrieval_generation' => 1, 'passage_retrieval_extractor_revision' => 1,
        ]);
        $page = $this->createPageWithApprovedStatus($owner, $workspace, 'Ledger', 'A receipt is required');
        config(['database.connections.passage_policy_competitor' => DB::connection()->getConfig()]);
        $competitor = DB::connection('passage_policy_competitor');
        $competitor->statement("SET lock_timeout TO '150ms'");
        $disable = ['passage_retrieval_enabled' => false, 'passage_retrieval_generation' => 2, 'passage_retrieval_extractor_revision' => null];
        $attempted = false;
        $blocked = false;
        try {
            DB::listen(static function (QueryExecuted $statement) use ($competitor, $settings, $disable, &$attempted, &$blocked): void {
                if (!$attempted && str_contains($statement->sql, 'ts_rank_cd')) {
                    $attempted = true;
                    try {
                        $competitor->table('installation_settings')->where('uid', $settings->uid)->update($disable);
                    } catch (QueryException $error) {
                        self::assertSame('55P03', (string) $error->getCode());
                        $blocked = true;
                    }
                }
            });
            $payload = $this->successfulToolPayload($this->callTool($token, 'search_passages', ['query' => 'receipt']));
            self::assertSame([$page->uid], array_column($this->payloadList($payload, 'results'), 'page_uid'));
            self::assertTrue($blocked);
            self::assertSame(1, $competitor->table('installation_settings')->where('uid', $settings->uid)->update($disable));
            $this->callTool($token, 'search_passages', ['query' => 'receipt'])->assertStatus(400)->assertJsonPath('error.code', -32602);
        } finally {
            DB::purge('passage_policy_competitor');
        }
    }
}
