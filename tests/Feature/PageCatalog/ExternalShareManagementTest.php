<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\ExternalSharing\ExternalShareSecret;
use App\Application\ExternalSharing\ExternalShareSessionCredential;
use App\Application\ExternalSharing\ListExternalShares;
use App\Application\ExternalSharing\RevokeExternalShare;
use App\Application\ExternalSharing\RevokeExternalShareCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Domain\ExternalSharing\ExternalShareStatus;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class ExternalShareManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_share_schema_uses_uids_and_database_shape_constraints(): void
    {
        foreach (['external_shares', 'external_share_sessions'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'uid'));
            $this->assertFalse(Schema::hasColumn($table, 'id'));
        }

        foreach ([
            'external_shares_mode_check',
            'external_shares_shape_check',
            'external_shares_secret_hash_check',
            'external_shares_access_revision_check',
            'external_shares_view_session_count_check',
            'external_share_sessions_kind_check',
            'external_share_sessions_credential_hash_check',
            'external_share_sessions_shape_check',
        ] as $constraint) {
            $this->assertTrue(
                DB::table('pg_constraint')->where('conname', $constraint)->exists(),
                "Missing expected constraint {$constraint}.",
            );
        }

        $sessionExpiryNullable = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'external_share_sessions')
            ->where('column_name', 'expires_at')
            ->value('is_nullable');
        $this->assertSame('YES', $sessionExpiryNullable);

        foreach ([
            'external_sharing_enabled',
            'external_share_acknowledgement_required',
            'external_share_max_expiry_hours',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('installation_settings', $column));
        }

        $revokerForeignKey = DB::table('pg_constraint')
            ->where('conname', 'external_shares_revoked_by_user_uid_foreign')
            ->value('confdeltype');

        $this->assertSame('a', $revokerForeignKey);
    }

    public function test_access_manager_can_create_a_hash_only_one_time_share_with_traceability(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();

        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand(
                pageUid: $page->uid,
                mode: ExternalShareMode::OneTime,
                expiresAt: null,
            ),
        );

        $share = $issued->share;
        $secret = $issued->secret();

        $this->assertSame(43, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret);
        $this->assertSame(64, strlen($share->secret_hash));
        $this->assertNotSame($secret, $share->secret_hash);
        $this->assertTrue(app(ExternalShareSecret::class)->matches($secret, $share->secret_hash));
        $this->assertSame(ExternalShareMode::OneTime, $share->mode);
        $this->assertNull($share->expires_at);
        $this->assertSame($page->workspace_uid, $share->page_workspace_uid);
        $this->assertSame($page->preview_access_revision, $share->page_access_revision);
        $this->assertSame($owner->uid, $share->created_by_user_uid);
        $this->assertTrue($share->page()->firstOrFail()->is($page));
        $this->assertTrue($share->creator()->firstOrFail()->is($owner));

        $event = DomainEvent::query()
            ->where('event_type', 'page.external_share.created')
            ->sole();
        $audit = AuditEntry::query()
            ->where('action', 'page.external_share.created')
            ->sole();

        $this->assertSame($page->uid, $event->aggregate_uid);
        $this->assertSame($share->uid, $event->payload['external_share_uid']);
        $this->assertSame('one_time', $event->payload['mode']);
        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($share->uid, $audit->auditable_uid);

        $serializedTrace = json_encode([$event->payload, $audit->metadata], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $serializedTrace);
        $this->assertStringNotContainsString($share->secret_hash, $serializedTrace);
    }

    public function test_expiring_share_requires_a_future_expiry_inside_the_installation_maximum(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing(maxExpiryHours: 48);
        $this->travelTo(CarbonImmutable::parse('2026-07-29T12:00:00Z'));

        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand(
                pageUid: $page->uid,
                mode: ExternalShareMode::ExpiresAt,
                expiresAt: CarbonImmutable::parse('2026-07-30T12:00:00Z'),
            ),
        );

        $this->assertTrue($issued->share->expires_at?->equalTo('2026-07-30T12:00:00Z') ?? false);

        foreach ([
            new CreateExternalShareCommand($page->uid, ExternalShareMode::ExpiresAt, null),
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::ExpiresAt,
                CarbonImmutable::parse('2026-07-29T11:59:59Z'),
            ),
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::ExpiresAt,
                CarbonImmutable::parse('2026-07-31T12:00:01Z'),
            ),
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::OneTime,
                CarbonImmutable::parse('2026-07-30T12:00:00Z'),
            ),
        ] as $invalid) {
            try {
                app(CreateExternalShare::class)->handle($owner, $invalid);
                $this->fail('Expected invalid external share expiry to be rejected.');
            } catch (DomainRuleViolation) {
                // Expected.
            }
        }

        $this->assertSame(1, ExternalShare::query()->count());
    }

    public function test_disabled_policy_unauthorized_users_and_service_accounts_cannot_create_shares(): void
    {
        [$owner, $page] = $this->pageFixture();

        try {
            app(CreateExternalShare::class)->handle(
                $owner,
                new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
            );
            $this->fail('Expected disabled external sharing to fail closed.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('External sharing is disabled for this installation.', $exception->getMessage());
        }

        $this->enableExternalSharing();
        $reader = app(CreateUser::class)->handle(
            'Reader User',
            'external-share-reader@example.test',
            'correct horse battery staple',
        );
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $page->workspace_uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        try {
            app(CreateExternalShare::class)->handle(
                $reader,
                new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
            );
            $this->fail('Expected a reader to be denied external sharing.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $owner->forceFill(['is_service_account' => true])->save();

        try {
            app(CreateExternalShare::class)->handle(
                $owner->refresh(),
                new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
            );
            $this->fail('Expected a service account to be denied external sharing.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only human access managers can create external shares.', $exception->getMessage());
        }

        $this->assertSame(0, ExternalShare::query()->count());
    }

    public function test_mcp_share_creation_rechecks_the_workspace_editor_sharing_policy_under_the_page_lock(): void
    {
        Storage::fake('artifacts');
        $this->enableExternalSharing();
        $workspaceOwner = app(CreateUser::class)->handle(
            'MCP Share Policy Owner',
            'mcp-share-policy-owner-handler@example.test',
            'correct horse battery staple',
        );
        $service = app(CreateUser::class)->handle(
            'MCP Share Policy Agent',
            'mcp-share-policy-agent-handler@example.test',
            'correct horse battery staple',
        );
        $service->forceFill(['is_service_account' => true])->save();
        $workspace = app(CreateSharedWorkspace::class)->handle(
            $workspaceOwner,
            'MCP Share Policy Handler Team',
        );
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $service->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($service, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Policy-controlled MCP handler target',
            description: null,
            content: '# Policy controlled',
        ));
        $workspace->forceFill(['allow_editor_page_sharing' => false])->save();
        $token = app(McpAccessTokenIssuer::class)->issue(
            principal: $service,
            name: 'MCP share policy test',
            scopes: [McpAccessTokenIssuer::SCOPE_SHARE],
            expiresAt: now()->addHour(),
            workspaceUids: [$workspace->uid],
        );
        $context = app(McpRequestContext::class);
        $context->activate($token->accessToken, 'share-policy-test');

        try {
            app(CreateExternalShare::class)->handleForMcp(
                $service,
                new CreateExternalShareCommand(
                    $page->uid,
                    ExternalShareMode::OneTime,
                    null,
                ),
            );
            $this->fail('Expected the workspace editor sharing policy to deny MCP creation.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'MCP may share only an owned editable page when its workspace permits editor sharing.',
                $exception->getMessage(),
            );
        } finally {
            $context->clear();
        }

        $this->assertSame(0, ExternalShare::query()->count());
    }

    public function test_active_share_limit_is_enforced_under_the_page_lock(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();
        config(['external_sharing.max_active_per_page' => 1]);

        app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('This page has reached its active external share limit.');

        app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );
    }

    public function test_access_manager_can_list_and_revoke_active_shares_without_revealing_secrets(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );

        $inventory = app(ListExternalShares::class)->forPage($owner, $page->uid);
        $items = $inventory->items;

        $this->assertCount(1, $items);
        $this->assertFalse($inventory->hasMore);
        $this->assertSame($issued->share->uid, $items[0]->uid);
        $this->assertSame(ExternalShareStatus::Active, $items[0]->status);
        $publicFields = array_keys(get_object_vars($items[0]));
        $this->assertNotContains('secret', $publicFields);
        $this->assertNotContains('secretHash', $publicFields);

        $revoked = app(RevokeExternalShare::class)->handle(
            $owner,
            new RevokeExternalShareCommand($page->uid, $issued->share->uid),
        );

        $this->assertTrue($revoked);
        $this->assertNotNull($issued->share->refresh()->revoked_at);
        $this->assertSame($owner->uid, $issued->share->revoked_by_user_uid);
        $this->assertFalse(app(RevokeExternalShare::class)->handle(
            $owner,
            new RevokeExternalShareCommand($page->uid, $issued->share->uid),
        ));

        $items = app(ListExternalShares::class)->forPage($owner, $page->uid)->items;
        $this->assertSame(ExternalShareStatus::Revoked, $items[0]->status);
        $this->assertSame(1, DomainEvent::query()
            ->where('event_type', 'page.external_share.revoked')
            ->count());
        $this->assertSame(1, AuditEntry::query()
            ->where('action', 'page.external_share.revoked')
            ->count());
    }

    public function test_non_manager_cannot_list_or_revoke_external_shares(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();
        $share = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;
        $outsider = app(CreateUser::class)->handle(
            'Outsider User',
            'external-share-outsider@example.test',
            'correct horse battery staple',
        );

        try {
            app(ListExternalShares::class)->forPage($outsider, $page->uid);
            $this->fail('Expected share inventory to require manage-access authority.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->expectException(AuthorizationException::class);

        app(RevokeExternalShare::class)->handle(
            $outsider,
            new RevokeExternalShareCommand($page->uid, $share->uid),
        );
    }

    public function test_archived_pages_and_the_installation_active_share_cap_are_enforced(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();
        $page->forceFill(['status' => \App\Domain\PageCatalog\PageStatus::Archived])->save();

        try {
            app(CreateExternalShare::class)->handle(
                $owner,
                new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
            );
            $this->fail('Expected archived page sharing to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Archived pages cannot be shared externally.', $exception->getMessage());
        }

        $page->forceFill(['status' => \App\Domain\PageCatalog\PageStatus::Draft])->save();
        config(['external_sharing.max_active_per_installation' => 1]);
        app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );
        $secondPage = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $page->workspace_uid,
            type: PageType::Markdown,
            title: 'Second external-share page',
            description: null,
            content: '# Second external-share page',
        ));

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('This installation has reached its active external share limit.');

        app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($secondPage->uid, ExternalShareMode::OneTime, null),
        );
    }

    public function test_invalidated_share_status_matches_live_page_authority_and_releases_capacity(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();
        config(['external_sharing.max_active_per_page' => 1]);
        $share = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;

        $page->forceFill(['preview_access_revision' => $page->preview_access_revision + 1])->save();
        $inventory = app(ListExternalShares::class)->forPage($owner, $page->uid);

        $this->assertSame(ExternalShareStatus::Invalidated, $inventory->items[0]->status);

        $replacement = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;

        $this->assertNotSame($share->uid, $replacement->uid);
    }

    public function test_redeemed_one_time_share_can_be_revoked_and_its_view_session_is_deleted(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();
        $share = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;
        $share->forceFill(['redeemed_at' => now()])->save();
        $session = ExternalShareSession::query()->forceCreate([
            'external_share_uid' => $share->uid,
            'credential_hash' => hash('sha256', 'winning-view-session'),
            'kind' => 'view',
            'expires_at' => null,
        ]);

        $redeemed = app(ListExternalShares::class)->forPage($owner, $page->uid)->items[0];
        $this->assertSame(ExternalShareStatus::Redeemed, $redeemed->status);
        $this->assertTrue($redeemed->hasOpenViewSession);
        $this->assertTrue($session->externalShare()->firstOrFail()->is($share));
        $this->assertTrue($share->sessions()->whereKey($session->uid)->exists());

        $revoked = app(RevokeExternalShare::class)->handle(
            $owner,
            new RevokeExternalShareCommand($page->uid, $share->uid),
        );

        $this->assertTrue($revoked);
        $this->assertNotNull($share->refresh()->revoked_at);
        $this->assertSame(0, ExternalShareSession::query()
            ->where('external_share_uid', $share->uid)
            ->count());
        $revoked = app(ListExternalShares::class)->forPage($owner, $page->uid)->items[0];
        $this->assertSame(ExternalShareStatus::Revoked, $revoked->status);
        $this->assertFalse($revoked->hasOpenViewSession);
    }

    public function test_external_share_secret_rejects_malformed_wrong_and_noncanonical_credentials(): void
    {
        $secrets = app(ExternalShareSecret::class);
        $generated = $secrets->generate();
        $other = $secrets->generate();

        $this->assertFalse($secrets->matches('', $generated->hash));
        $this->assertFalse($secrets->matches($other->reveal(), $generated->hash));
        $this->assertFalse($secrets->matches($generated->reveal() . '=', $generated->hash));
        $this->assertFalse($secrets->matches($generated->reveal(), 'not-a-sha256-hash'));
    }

    public function test_external_share_session_credentials_reject_malformed_and_noncanonical_values(): void
    {
        $credentials = app(ExternalShareSessionCredential::class);
        $generated = $credentials->generate(ExternalShareSessionKind::View);

        $this->assertSame(
            $generated->hash,
            $credentials->hashForLookup(ExternalShareSessionKind::View, $generated->reveal()),
        );
        $this->assertNull($credentials->hashForLookup(ExternalShareSessionKind::View, ''));
        $this->assertNull($credentials->hashForLookup(
            ExternalShareSessionKind::View,
            $generated->reveal() . '=',
        ));
        $this->assertNotSame(
            $generated->hash,
            $credentials->hashForLookup(ExternalShareSessionKind::PendingOpen, $generated->reveal()),
        );
    }

    public function test_external_share_inventory_is_bounded_and_cursor_pageable(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->enableExternalSharing();

        for ($index = 0; $index < 3; ++$index) {
            app(CreateExternalShare::class)->handle(
                $owner,
                new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
            );
            $this->travel(1)->seconds();
        }

        $first = app(ListExternalShares::class)->forPage($owner, $page->uid, pageSize: 2);

        $this->assertCount(2, $first->items);
        $this->assertTrue($first->hasMore);

        $last = $first->items[count($first->items) - 1];
        $second = app(ListExternalShares::class)->forPage(
            $owner,
            $page->uid,
            pageSize: 2,
            beforeCreatedAt: $last->createdAt,
            beforeUid: $last->uid,
        );

        $this->assertCount(1, $second->items);
        $this->assertFalse($second->hasMore);
        $this->assertNotContains($second->items[0]->uid, array_map(
            static fn ($item): string => $item->uid,
            $first->items,
        ));

        $this->expectException(InvalidArgumentException::class);

        app(ListExternalShares::class)->forPage($owner, $page->uid, pageSize: 101);
    }

    /**
     * @return array{User, Page}
     */
    private function pageFixture(): array
    {
        Storage::fake('artifacts');

        $owner = app(CreateUser::class)->handle(
            'External Share Owner',
            'external-share-owner@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'External Sharing Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Externally shared runbook',
            description: null,
            content: '# Externally shared runbook',
        ));

        return [$owner, $page];
    }

    private function enableExternalSharing(int $maxExpiryHours = 168): void
    {
        $values = app(InstallationLimitSettings::class)->current();

        InstallationSettings::query()->forceCreate(array_merge($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => true,
            'external_share_acknowledgement_required' => true,
            'external_share_max_expiry_hours' => $maxExpiryHours,
        ]));
    }
}
