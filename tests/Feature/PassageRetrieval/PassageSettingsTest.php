<?php

declare(strict_types=1);

namespace Tests\Feature\PassageRetrieval;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Administration\UpdatePassageRetrieval;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\PageCatalog\PageType;
use App\Http\Middleware\RequireRecentSystemAdminTwoFactorConfirmation;
use App\Jobs\BackfillPassageIndex;
use App\Jobs\PurgePassageGeneration;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\InstallationSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PassageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/admin/settings/passage-retrieval';
    private const string EVENT = 'installation.passage_retrieval.updated';

    public function test_admin_can_change_policy_with_traceability_and_replays_and_stale_forms_are_safe(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->timestamp]);
        $this->get('/admin/settings')->assertOk()->assertSee('Passage retrieval for MCP');
        $this->put(self::URL, ['enabled' => 0, 'generation' => 0])->assertRedirect('/admin/settings');
        $this->assertDatabaseCount('installation_settings', 0);
        $this->put(self::URL, ['enabled' => 1, 'generation' => 0])->assertRedirect('/admin/settings')->assertSessionHasNoErrors();
        $this->put(self::URL, ['enabled' => 1, 'generation' => 0])->assertRedirect('/admin/settings')->assertSessionHasNoErrors();
        $this->put(self::URL, ['enabled' => 0, 'generation' => 0])->assertSessionHasErrors('generation');
        $policy = InstallationSettings::query()->sole();
        self::assertTrue($policy->passage_retrieval_enabled);
        self::assertSame(1, $policy->passage_retrieval_generation);
        $event = DomainEvent::query()->where('event_type', self::EVENT)->sole();
        $audit = AuditEntry::query()->where('action', self::EVENT)->sole();
        self::assertSame($policy->uid, $event->aggregate_uid);
        self::assertSame($admin->uid, $audit->actor_user_uid);
        self::assertSame($event->uid, $audit->event_uid);
        self::assertFalse($event->payload['previous_enabled']);
        self::assertTrue($event->payload['enabled']);
        self::assertSame(1, $event->payload['generation']);
        Queue::assertPushed(BackfillPassageIndex::class, 1);

        $this->put('/admin/settings', app(InstallationLimitSettings::class)->current()->toPersistenceArray() + ['passage_retrieval_enabled' => false])
            ->assertSessionHasNoErrors();
        self::assertTrue($policy->refresh()->passage_retrieval_enabled);
        $this->put(self::URL, ['enabled' => 0, 'generation' => 1])->assertSessionHasNoErrors();
        self::assertFalse($policy->refresh()->passage_retrieval_enabled);
        self::assertSame(2, $policy->passage_retrieval_generation);
        self::assertNull($policy->passage_retrieval_extractor_revision);
        Queue::assertPushed(PurgePassageGeneration::class, static fn (PurgePassageGeneration $job): bool => $job->generation === 1);
    }

    public function test_policy_and_audit_commit_even_when_backfill_dispatch_fails(): void
    {
        Bus::shouldReceive('dispatch')->once()->with(\Mockery::type(BackfillPassageIndex::class))
            ->andThrow(new RuntimeException('private queue details'));
        Log::shouldReceive('warning')->once()->with('passage_retrieval.dispatch_failed', ['generation' => 1]);

        $admin = $this->admin();
        $this->actingAs($admin)->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->timestamp])
            ->put(self::URL, ['enabled' => 1, 'generation' => 0])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('installation_settings', ['passage_retrieval_enabled' => true, 'passage_retrieval_generation' => 1]);
        $this->assertDatabaseHas('audit_entries', ['action' => self::EVENT, 'actor_user_uid' => $admin->uid]);
        $this->assertDatabaseHas('domain_events', ['event_type' => self::EVENT]);
    }

    public function test_route_requires_admin_fresh_two_factor_and_valid_fields_and_handler_checks_the_actor(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->put(self::URL, ['enabled' => 1, 'generation' => 0])->assertForbidden();
        $this->actingAs($this->admin())->put(self::URL, ['enabled' => 1, 'generation' => 0])->assertRedirect(route('admin.two-factor.confirm'));
        $this->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->timestamp])
            ->put(self::URL, ['enabled' => 'perhaps', 'generation' => -1])->assertSessionHasErrors(['enabled', 'generation']);
        $this->assertDatabaseCount('installation_settings', 0);
        $this->expectException(AuthorizationException::class);
        app(UpdatePassageRetrieval::class)->handle($member, true, 0);
    }

    public function test_progress_counts_current_versions_and_retired_records_without_exposing_private_text(): void
    {
        Storage::fake('artifacts');
        Queue::fake();
        $author = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($author, 'Private workspace');
        $admin = $this->admin();
        $this->actingAs($admin)->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->timestamp]);
        $this->put(self::URL, ['enabled' => 1, 'generation' => 0])->assertSessionHasNoErrors();
        app(CreatePage::class)->handle($author, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Private page title',
            description: null,
            content: 'Confidential evidence',
        ));
        $this->get('/admin/settings')->assertOk()->assertSee('1 of 1 current versions indexed')
            ->assertDontSee('Private page title')->assertDontSee('Confidential evidence');
        $this->put(self::URL, ['enabled' => 0, 'generation' => 1])->assertSessionHasNoErrors();
        $this->get('/admin/settings')->assertOk()->assertSee('1 record awaiting cleanup')
            ->assertDontSee('Private page title')->assertDontSee('Confidential evidence');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_system_admin' => true, 'two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
    }
}
