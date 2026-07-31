<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\PageCatalog\PageType;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\InstallationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class PruneExternalSharesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_stale_sessions_and_terminal_shares_after_their_retention_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-30T12:00:00Z'));
        [$oldExpired, $recentExpired, $active, $redeemed, $revoked, $openRedeemed]
            = $this->shareFixture();
        $this->externalSession($active, 'old-pending', CarbonImmutable::now()->subHours(25));
        $this->externalSession($active, 'recent-pending', CarbonImmutable::now()->subHours(23));
        $this->externalSession(
            $oldExpired,
            'cascaded-pending',
            CarbonImmutable::now()->subDays(100),
        );

        $this->runConsoleCommand('artifactflow:prune-external-shares')
            ->expectsOutput(
                'Pruned 2 stale external-share sessions and 3 terminal external shares.',
            )
            ->assertSuccessful();

        $this->assertFalse(ExternalShare::query()->whereKey($oldExpired->uid)->exists());
        $this->assertFalse(ExternalShare::query()->whereKey($redeemed->uid)->exists());
        $this->assertFalse(ExternalShare::query()->whereKey($revoked->uid)->exists());
        $this->assertTrue(ExternalShare::query()->whereKey($openRedeemed->uid)->exists());
        $this->assertTrue(ExternalShare::query()->whereKey($recentExpired->uid)->exists());
        $this->assertTrue(ExternalShare::query()->whereKey($active->uid)->exists());
        $this->assertFalse(
            ExternalShareSession::query()
                ->where('credential_hash', hash('sha256', 'old-pending'))
                ->exists(),
        );
        $this->assertTrue(
            ExternalShareSession::query()
                ->where('credential_hash', hash('sha256', 'recent-pending'))
                ->exists(),
        );
        $this->assertTrue(
            ExternalShareSession::query()
                ->where('credential_hash', hash('sha256', 'window-lived-view'))
                ->exists(),
        );
    }

    public function test_dry_run_reports_counts_without_deleting(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-30T12:00:00Z'));
        [$oldExpired, , $active] = $this->shareFixture();
        $this->externalSession($active, 'old-pending', CarbonImmutable::now()->subHours(25));

        $this->runConsoleCommand('artifactflow:prune-external-shares', ['--dry-run' => true])
            ->expectsOutput(
                'Would prune 1 stale external-share session and 3 terminal external shares.',
            )
            ->assertSuccessful();

        $this->assertTrue(ExternalShare::query()->whereKey($oldExpired->uid)->exists());
        $this->assertTrue(
            ExternalShareSession::query()
                ->where('credential_hash', hash('sha256', 'old-pending'))
                ->exists(),
        );
    }

    /**
     * @return array{
     *     ExternalShare,
     *     ExternalShare,
     *     ExternalShare,
     *     ExternalShare,
     *     ExternalShare,
     *     ExternalShare
     * }
     */
    private function shareFixture(): array
    {
        Storage::fake('artifacts');
        $owner = app(CreateUser::class)->handle(
            'External Prune Owner',
            'external-prune-owner@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'External Prune Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Prunable external shares',
            description: null,
            content: '# Prunable',
        ));
        $values = app(InstallationLimitSettings::class)->current();
        InstallationSettings::query()->forceCreate(array_merge($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => true,
            'external_share_acknowledgement_required' => true,
            'external_share_max_expiry_hours' => 720,
        ]));

        $oldExpired = $this->share(
            $owner,
            $page->uid,
            ExternalShareMode::ExpiresAt,
            CarbonImmutable::now()->addHour(),
        );
        $oldExpired->forceFill(['expires_at' => CarbonImmutable::now()->subDays(91)])->save();
        $recentExpired = $this->share(
            $owner,
            $page->uid,
            ExternalShareMode::ExpiresAt,
            CarbonImmutable::now()->addHour(),
        );
        $recentExpired->forceFill(['expires_at' => CarbonImmutable::now()->subDays(89)])->save();
        $active = $this->share(
            $owner,
            $page->uid,
            ExternalShareMode::ExpiresAt,
            CarbonImmutable::now()->addDay(),
        );
        $redeemed = $this->share($owner, $page->uid, ExternalShareMode::OneTime);
        $redeemed->forceFill(['redeemed_at' => CarbonImmutable::now()->subDays(91)])->save();
        $revoked = $this->share($owner, $page->uid, ExternalShareMode::OneTime);
        $revoked->forceFill([
            'revoked_at' => CarbonImmutable::now()->subDays(91),
            'revoked_by_user_uid' => $owner->uid,
        ])->save();
        $openRedeemed = $this->share($owner, $page->uid, ExternalShareMode::OneTime);
        $openRedeemed->forceFill(['redeemed_at' => CarbonImmutable::now()->subDays(91)])->save();
        $this->externalSession($openRedeemed, 'window-lived-view', null, 'view');

        return [$oldExpired, $recentExpired, $active, $redeemed, $revoked, $openRedeemed];
    }

    private function share(
        \App\Models\User $owner,
        string $pageUid,
        ExternalShareMode $mode,
        ?CarbonImmutable $expiresAt = null,
    ): ExternalShare {
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($pageUid, $mode, $expiresAt),
        );

        return $issued->share;
    }

    private function externalSession(
        ExternalShare $share,
        string $label,
        ?CarbonImmutable $expiresAt,
        string $kind = 'pending_open',
    ): void {
        ExternalShareSession::query()->forceCreate([
            'external_share_uid' => $share->uid,
            'credential_hash' => hash('sha256', $label),
            'kind' => $kind,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runConsoleCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
