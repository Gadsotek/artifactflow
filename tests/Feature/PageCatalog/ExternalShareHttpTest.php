<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\ExternalShare;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExternalShareHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_manager_sees_the_external_share_action_and_creation_dialog(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, maxExpiryHours: 48);

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Share externally')
            ->assertSee('data-open-editor-dialog="page-external-share-dialog"', false)
            ->assertSee('id="page-external-share-dialog"', false)
            ->assertSee('name="mode"', false)
            ->assertSee('value="expires_at"', false)
            ->assertSee('value="one_time"', false)
            ->assertSee('name="expires_at"', false)
            ->assertSee('data-external-share-max-expiry-hours="48"', false)
            ->assertSee('Create external link')
            ->assertSee('48 hours');
    }

    public function test_reader_does_not_receive_the_external_share_action_or_inventory(): void
    {
        [$owner, $page] = $this->pageFixture();
        $reader = app(CreateUser::class)->handle(
            'External Share Reader',
            'external-share-http-reader@example.test',
            'correct horse battery staple',
        );
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $page->workspace_uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $this->configureExternalSharing(enabled: true);

        $this->actingAs($reader)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('Share externally')
            ->assertDontSee('page-external-share-dialog', false);
    }

    public function test_disabled_installation_keeps_the_manager_action_visible_but_disabled(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: false);

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Share externally')
            ->assertSee('data-external-sharing-disabled', false)
            ->assertSee('External sharing is disabled for this installation.');
    }

    public function test_access_manager_can_create_a_one_time_link_returned_only_in_the_json_response(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true);

        $response = $this->actingAs($owner)
            ->postJson("/pages/{$page->uid}/external-shares", [
                'mode' => ExternalShareMode::OneTime->value,
            ])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('share.mode', ExternalShareMode::OneTime->value)
            ->assertJsonPath('share.status', 'active')
            ->assertJsonPath('share.expires_at', null);

        $share = ExternalShare::query()->sole();
        $url = $response->json('url');

        $this->assertIsString($url);
        $this->assertMatchesRegularExpression(
            '#^' . preg_quote(route('external-shares.bootstrap', ['externalShareUid' => $share->uid]), '#')
                . '\#secret=[A-Za-z0-9_-]{43}$#',
            $url,
        );
        $this->assertStringNotContainsString($share->secret_hash, $url);
        $this->assertDatabaseMissing('external_shares', ['secret_hash' => substr($url, -43)]);

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee($share->uid)
            ->assertSee('One time')
            ->assertSee('Active')
            ->assertDontSee($url, false)
            ->assertDontSee(substr($url, -43), false);
    }

    public function test_external_share_creation_rejects_invalid_mode_and_expiry_shapes(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, maxExpiryHours: 48);

        foreach ([
            [[], ['mode']],
            [['mode' => 'forever'], ['mode']],
            [['mode' => ExternalShareMode::ExpiresAt->value], ['expires_at']],
            [[
                'mode' => ExternalShareMode::OneTime->value,
                'expires_at' => now()->addHour()->toISOString(),
            ], ['expires_at']],
            [[
                'mode' => ExternalShareMode::ExpiresAt->value,
                'expires_at' => 'not-a-date',
            ], ['expires_at']],
            [[
                'mode' => ExternalShareMode::ExpiresAt->value,
                'expires_at' => now()->subMinute()->toISOString(),
            ], ['expires_at']],
            [[
                'mode' => ExternalShareMode::ExpiresAt->value,
                'expires_at' => now()->addHours(49)->toISOString(),
            ], ['expires_at']],
        ] as [$payload, $errorFields]) {
            $this->actingAs($owner)
                ->postJson("/pages/{$page->uid}/external-shares", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorFields);
        }

        $this->assertDatabaseCount('external_shares', 0);
    }

    public function test_non_manager_cannot_create_or_revoke_an_external_share_over_http(): void
    {
        [$owner, $page] = $this->pageFixture();
        $outsider = app(CreateUser::class)->handle(
            'External Share Outsider',
            'external-share-http-outsider@example.test',
            'correct horse battery staple',
        );
        $this->configureExternalSharing(enabled: true);
        $share = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;

        $this->actingAs($outsider)
            ->postJson("/pages/{$page->uid}/external-shares", [
                'mode' => ExternalShareMode::OneTime->value,
            ])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->delete("/pages/{$page->uid}/external-shares/{$share->uid}")
            ->assertNotFound();

        $this->assertNull($share->refresh()->revoked_at);
    }

    public function test_access_manager_can_revoke_an_active_share_from_the_inventory(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true);
        $share = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;

        $this->actingAs($owner)
            ->delete("/pages/{$page->uid}/external-shares/{$share->uid}")
            ->assertRedirect("/pages/{$page->uid}")
            ->assertSessionHas('status', 'External share revoked.');

        $this->assertNotNull($share->refresh()->revoked_at);
    }

    public function test_redeemed_one_time_share_keeps_its_revoke_action_in_the_inventory(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true);
        $share = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        )->share;
        $share->forceFill(['redeemed_at' => now()])->save();

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Redeemed')
            ->assertSee(
                route('pages.external-shares.destroy', [$page, $share->uid]),
                false,
            )
            ->assertSee('Revoke');
    }

    /**
     * @return array{User, Page}
     */
    private function pageFixture(): array
    {
        Storage::fake('artifacts');
        $owner = app(CreateUser::class)->handle(
            'External Share HTTP Owner',
            'external-share-http-owner@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'External Sharing HTTP Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Externally shareable runbook',
            description: null,
            content: '# Externally shareable runbook',
        ));

        return [$owner, $page];
    }

    private function configureExternalSharing(bool $enabled, int $maxExpiryHours = 168): void
    {
        $values = app(InstallationLimitSettings::class)->current();

        InstallationSettings::query()->forceCreate(array_merge($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => $enabled,
            'external_share_acknowledgement_required' => true,
            'external_share_max_expiry_hours' => $maxExpiryHours,
        ]));
    }
}
