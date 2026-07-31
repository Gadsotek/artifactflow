<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\ExternalSharing\ExternalShareOpenCsrf;
use App\Application\ExternalSharing\ExternalShareSessionCredential;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareSessionKind;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\ExternalShare;
use App\Models\ExternalShareSession;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

final class ExternalSharePublicFlowTest extends TestCase
{
    use RefreshDatabase;

    private const string PENDING_COOKIE = 'artifactflow_external_pending';

    private const string VIEW_COOKIE = 'artifactflow_external_view';

    public function test_bootstrap_is_uniform_session_free_and_does_not_receive_the_fragment_secret(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );
        $secret = $issued->secret();

        foreach ([$issued->share->uid, str_repeat('0', 26)] as $selector) {
            $response = $this->get("/external-shares/{$selector}")
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertSee('Opening external artifact')
                ->assertDontSee($page->title)
                ->assertDontSee($secret);

            $this->assertSame([], $response->headers->getCookies());
            $csp = (string) $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("form-action 'none'", $csp);
            $this->assertStringContainsString("connect-src 'self'", $csp);
            $this->assertStringNotContainsString('challenges.cloudflare.com', $csp);
        }

        $this->assertNull($issued->share->refresh()->redeemed_at);
    }

    public function test_valid_one_time_secret_exchanges_for_a_pending_confirmation_without_consumption(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: true);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );

        $response = $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => $issued->secret(),
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('state', 'confirmation')
            ->assertJsonPath('artifact.title', $page->title)
            ->assertJsonPath('artifact.mode', ExternalShareMode::OneTime->value)
            ->assertJsonPath('artifact.acknowledgement_required', true);

        $csrfToken = $response->json('csrf_token');
        $this->assertIsString($csrfToken);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $csrfToken);
        $cookie = $this->responseCookie($response->headers->getCookies(), self::PENDING_COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        $this->assertSame("/external-shares/{$issued->share->uid}", $cookie->getPath());

        $pending = ExternalShareSession::query()->sole();
        $this->assertSame('pending_open', $pending->kind);
        $this->assertNotSame($cookie->getValue(), $pending->credential_hash);
        $this->assertNull($issued->share->refresh()->redeemed_at);

        $serialized = $response->getContent();
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString($issued->secret(), $serialized);
        $this->assertStringNotContainsString($issued->share->secret_hash, $serialized);
    }

    public function test_external_share_cookies_follow_the_configured_secure_session_policy(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: true);
        config(['session.secure' => true]);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );

        $exchange = $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => $issued->secret(),
            ])
            ->assertOk();
        $pendingCookie = $this->responseCookie(
            $exchange->headers->getCookies(),
            self::PENDING_COOKIE,
        );

        $this->assertTrue($pendingCookie->isSecure());

        $open = $this->withHeaders($this->sameOriginHeaders())
            ->withCredentials()
            ->withUnencryptedCookie(self::PENDING_COOKIE, $this->cookieValue($pendingCookie))
            ->postJson("/external-shares/{$issued->share->uid}/open", [
                'csrf_token' => $exchange->json('csrf_token'),
            ])
            ->assertOk();

        $this->assertTrue($this->responseCookie(
            $open->headers->getCookies(),
            self::PENDING_COOKIE,
        )->isSecure());
        $this->assertTrue($this->responseCookie(
            $open->headers->getCookies(),
            self::VIEW_COOKIE,
        )->isSecure());
    }

    public function test_explicit_open_atomically_redeems_one_time_share_and_issues_a_window_lived_view_session(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: true);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );
        $firstExchange = $this->exchange($issued->share, $issued->secret());
        $secondExchange = $this->exchange($issued->share, $issued->secret());
        $firstPending = $this->responseCookie(
            $firstExchange->headers->getCookies(),
            self::PENDING_COOKIE,
        );
        $secondPending = $this->responseCookie(
            $secondExchange->headers->getCookies(),
            self::PENDING_COOKIE,
        );
        $firstPendingCredential = $this->cookieValue($firstPending);
        $secondPendingCredential = $this->cookieValue($secondPending);
        $firstPendingHash = app(ExternalShareSessionCredential::class)->hashForLookup(
            ExternalShareSessionKind::PendingOpen,
            $firstPendingCredential,
        );
        $this->assertIsString($firstPendingHash);
        $firstPendingSession = ExternalShareSession::query()
            ->where('credential_hash', $firstPendingHash)
            ->sole();
        $firstCsrf = $firstExchange->json('csrf_token');
        $this->assertIsString($firstCsrf);
        $this->assertTrue(app(ExternalShareOpenCsrf::class)->matches($firstPendingSession, $firstCsrf));

        $opened = $this->withHeaders($this->sameOriginHeaders())
            ->withCredentials()
            ->withUnencryptedCookie(self::PENDING_COOKIE, $firstPendingCredential)
            ->postJson("/external-shares/{$issued->share->uid}/open", [
                'csrf_token' => $firstCsrf,
            ])
            ->assertOk()
            ->assertJsonPath('state', 'viewer');

        $viewerUrl = $opened->json('viewer_url');
        $this->assertSame(
            route('external-shares.viewer', ['externalShareUid' => $issued->share->uid]),
            $viewerUrl,
        );
        $windowToken = $opened->json('window_token');
        $this->assertIsString($windowToken);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $windowToken);
        $viewCookie = $this->responseCookie($opened->headers->getCookies(), self::VIEW_COOKIE);
        $viewCredential = $this->cookieValue($viewCookie);
        $this->assertTrue($viewCookie->isHttpOnly());
        $this->assertSame(0, $viewCookie->getExpiresTime());
        $this->assertSame(Cookie::SAMESITE_STRICT, $viewCookie->getSameSite());
        $this->assertNotNull($issued->share->refresh()->redeemed_at);
        $this->assertSame(1, $issued->share->view_session_count);
        $this->assertSame(1, ExternalShareSession::query()->where('kind', 'view')->count());
        $this->assertNull(
            ExternalShareSession::query()->where('kind', 'view')->sole()->expires_at,
        );

        $this->withHeaders($this->sameOriginHeaders())
            ->withCredentials()
            ->withUnencryptedCookie(self::PENDING_COOKIE, $secondPendingCredential)
            ->postJson("/external-shares/{$issued->share->uid}/open", [
                'csrf_token' => $secondExchange->json('csrf_token'),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);

        $shell = $this->withUnencryptedCookie(self::VIEW_COOKIE, $viewCredential)
            ->get("/external-shares/{$issued->share->uid}/viewer")
            ->assertOk()
            ->assertSee('data-external-share-viewer-shell', false)
            ->assertSee('data-external-theme-bootstrap', false)
            ->assertSee('aria-label="Light theme"', false)
            ->assertSee('aria-label="Dark theme"', false)
            ->assertDontSee($windowToken)
            ->assertDontSee($page->title);

        $content = $this->withHeaders($this->sameOriginHeaders())
            ->withUnencryptedCookie(self::VIEW_COOKIE, $viewCredential)
            ->post("/external-shares/{$issued->share->uid}/viewer/content", [
                'window_token' => $windowToken,
            ])
            ->assertOk()
            ->assertSee($page->title)
            ->assertSee('Live · latest version')
            ->assertSee('Externally shareable runbook')
            ->assertDontSee('Library')
            ->assertDontSee('Copy page link')
            ->assertDontSee('Version history')
            ->assertDontSee($windowToken);

        $this->assertNotSame($shell->getContent(), $content->getContent());

        $this->withHeaders($this->sameOriginHeaders())
            ->withUnencryptedCookie(self::VIEW_COOKIE, $viewCredential)
            ->post("/external-shares/{$issued->share->uid}/viewer/content", [
                'window_token' => str_repeat('0', 64),
            ])
            ->assertNotFound()
            ->assertDontSee($page->title);
    }

    public function test_expiring_share_without_acknowledgement_enters_viewer_directly_and_stops_at_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-30T10:00:00Z'));
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: false);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::ExpiresAt,
                CarbonImmutable::parse('2026-07-30T11:00:00Z'),
            ),
        );

        $exchange = $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => $issued->secret(),
            ])
            ->assertOk()
            ->assertJsonPath('state', 'viewer')
            ->assertJsonMissingPath('csrf_token');
        $viewCookie = $this->responseCookie($exchange->headers->getCookies(), self::VIEW_COOKIE);
        $viewCredential = $this->cookieValue($viewCookie);
        $windowToken = $exchange->json('window_token');
        $this->assertIsString($windowToken);

        $this->viewerContent($issued->share, $viewCredential, $windowToken)
            ->assertOk()
            ->assertSee('Expires');

        $this->travelTo(CarbonImmutable::parse('2026-07-30T11:00:01Z'));

        $this->viewerContent($issued->share, $viewCredential, $windowToken)
            ->assertNotFound()
            ->assertSee('This external artifact is unavailable.')
            ->assertDontSee($page->title);
    }

    public function test_wrong_secret_cross_site_open_revocation_and_global_disable_share_one_unavailable_surface(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );

        $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => str_repeat('A', 43),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);

        $this->withHeaders([
            'Origin' => 'https://attacker.example',
            'Sec-Fetch-Site' => 'cross-site',
        ])->postJson("/external-shares/{$issued->share->uid}/exchange", [
            'secret' => $issued->secret(),
        ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);

        $exchange = $this->exchange($issued->share, $issued->secret());
        $pending = $this->responseCookie($exchange->headers->getCookies(), self::PENDING_COOKIE);
        $pendingCredential = $this->cookieValue($pending);
        $issued->share->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_uid' => $owner->uid,
        ])->save();

        $this->withHeaders($this->sameOriginHeaders())
            ->withCredentials()
            ->withUnencryptedCookie(self::PENDING_COOKIE, $pendingCredential)
            ->postJson("/external-shares/{$issued->share->uid}/open", [
                'csrf_token' => $exchange->json('csrf_token'),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);

        $issued->share->forceFill([
            'revoked_at' => null,
            'revoked_by_user_uid' => null,
        ])->save();
        InstallationSettings::query()->update(['external_sharing_enabled' => false]);

        $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => $issued->secret(),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);
    }

    public function test_public_exchange_rate_limit_remains_indistinguishable_from_an_unavailable_share(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true);
        config(['rate_limits.external_share_public_per_minute' => 1]);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withHeaders($this->sameOriginHeaders())
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
                ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                    'secret' => str_repeat('A', 43),
                ])
                ->assertNotFound()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertExactJson(['state' => 'unavailable']);
        }
    }

    public function test_viewer_content_rate_limit_uses_the_same_unavailable_html_surface(): void
    {
        config(['rate_limits.external_share_public_per_minute' => 1]);
        $selector = (string) Str::ulid();
        $responses = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $responses[] = $this->withHeaders([
                ...$this->sameOriginHeaders(),
                'Accept' => 'text/html',
            ])
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.13'])
                ->post("/external-shares/{$selector}/viewer/content", [
                    'window_token' => str_repeat('0', 64),
                ])
                ->assertNotFound()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-RateLimit-Limit', '1')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertSee('This external artifact is unavailable.');
        }

        $this->assertSame(
            $responses[0]->headers->get('Content-Type'),
            $responses[1]->headers->get('Content-Type'),
        );
        $this->assertSame($responses[0]->getContent(), $responses[1]->getContent());
    }

    public function test_external_share_routes_use_dedicated_creation_and_public_limiters(): void
    {
        foreach ([
            'external-shares.exchange',
            'external-shares.open',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertContains(
                'throttle:artifactflow-external-share-public',
                $route->gatherMiddleware(),
            );
        }

        $creation = Route::getRoutes()->getByName('pages.external-shares.store');
        $this->assertNotNull($creation);
        $this->assertContains(
            'throttle:artifactflow-external-share-creates',
            $creation->gatherMiddleware(),
        );
    }

    public function test_view_session_follows_the_latest_version_and_fails_closed_after_live_page_invalidation(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: false);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::ExpiresAt,
                CarbonImmutable::now()->addHour(),
            ),
        );
        $exchange = $this->exchange($issued->share, $issued->secret())
            ->assertJsonPath('state', 'viewer');
        $viewCookie = $this->responseCookie($exchange->headers->getCookies(), self::VIEW_COOKIE);
        $viewCredential = $this->cookieValue($viewCookie);
        $windowToken = $exchange->json('window_token');
        $this->assertIsString($windowToken);

        app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Latest shared revision',
            baseVersionUid: $page->current_version_uid,
        ));

        $this->viewerContent($issued->share, $viewCredential, $windowToken)
            ->assertOk()
            ->assertSee('Latest shared revision')
            ->assertSee('Version 2');

        $page->refresh()->forceFill([
            'preview_access_revision' => $page->preview_access_revision + 1,
        ])->save();

        $this->viewerContent($issued->share, $viewCredential, $windowToken)
            ->assertNotFound()
            ->assertDontSee($page->title);

        $page->forceFill(['status' => PageStatus::Archived])->save();

        $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => $issued->secret(),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);
    }

    public function test_one_time_view_session_remains_available_to_the_winning_window_without_an_arbitrary_timeout(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-30T10:00:00Z'));
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: false);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand($page->uid, ExternalShareMode::OneTime, null),
        );
        $exchange = $this->exchange($issued->share, $issued->secret());
        $pendingCookie = $this->responseCookie(
            $exchange->headers->getCookies(),
            self::PENDING_COOKIE,
        );
        $opened = $this->withHeaders($this->sameOriginHeaders())
            ->withCredentials()
            ->withUnencryptedCookie(self::PENDING_COOKIE, $this->cookieValue($pendingCookie))
            ->postJson("/external-shares/{$issued->share->uid}/open", [
                'csrf_token' => $exchange->json('csrf_token'),
            ])
            ->assertOk()
            ->assertJsonPath('state', 'viewer');
        $viewCookie = $this->responseCookie($opened->headers->getCookies(), self::VIEW_COOKIE);
        $viewCredential = $this->cookieValue($viewCookie);
        $windowToken = $opened->json('window_token');
        $this->assertIsString($windowToken);
        $this->assertSame(0, $viewCookie->getExpiresTime());

        $this->travelTo(CarbonImmutable::parse('2027-07-30T10:00:00Z'));
        $this->viewerContent($issued->share, $viewCredential, $windowToken)
            ->assertOk()
            ->assertSee($page->title)
            ->assertDontSee('Viewing session ends');

        $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$issued->share->uid}/exchange", [
                'secret' => $issued->secret(),
            ])
            ->assertNotFound()
            ->assertExactJson(['state' => 'unavailable']);
        $this->assertSame(1, $issued->share->refresh()->view_session_count);
    }

    public function test_expiring_share_evicts_the_oldest_view_session_at_the_per_share_ceiling(): void
    {
        [$owner, $page] = $this->pageFixture();
        $this->configureExternalSharing(enabled: true, acknowledgementRequired: false);
        config(['external_sharing.max_view_sessions_per_share' => 2]);
        $issued = app(CreateExternalShare::class)->handle(
            $owner,
            new CreateExternalShareCommand(
                $page->uid,
                ExternalShareMode::ExpiresAt,
                CarbonImmutable::now()->addHour(),
            ),
        );
        $sessions = [];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $exchange = $this->exchange($issued->share, $issued->secret())
                ->assertJsonPath('state', 'viewer');
            $windowToken = $exchange->json('window_token');
            $this->assertIsString($windowToken);
            $sessions[] = [
                'credential' => $this->cookieValue(
                    $this->responseCookie($exchange->headers->getCookies(), self::VIEW_COOKIE),
                ),
                'window_token' => $windowToken,
            ];
        }

        $this->assertSame(
            2,
            ExternalShareSession::query()
                ->where('external_share_uid', $issued->share->uid)
                ->where('kind', ExternalShareSessionKind::View->value)
                ->count(),
        );
        $this->assertSame(3, $issued->share->refresh()->view_session_count);

        $this->viewerContent(
            $issued->share,
            $sessions[0]['credential'],
            $sessions[0]['window_token'],
        )->assertNotFound();

        foreach (array_slice($sessions, 1) as $session) {
            $this->viewerContent(
                $issued->share,
                $session['credential'],
                $session['window_token'],
            )
                ->assertOk()
                ->assertSee($page->title);
        }
    }

    /**
     * @return array{User, Page}
     */
    private function pageFixture(): array
    {
        Storage::fake('artifacts');
        $owner = app(CreateUser::class)->handle(
            'External Public Owner',
            'external-share-public-owner@example.test',
            'correct horse battery staple',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'External Public Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Externally shareable runbook',
            description: 'Internal description must not be disclosed.',
            content: '# Externally shareable runbook',
        ));

        return [$owner, $page];
    }

    private function configureExternalSharing(
        bool $enabled,
        bool $acknowledgementRequired = true,
        int $maxExpiryHours = 168,
    ): void {
        $values = app(InstallationLimitSettings::class)->current();

        InstallationSettings::query()->forceCreate(array_merge($values->toPersistenceArray(), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'external_sharing_enabled' => $enabled,
            'external_share_acknowledgement_required' => $acknowledgementRequired,
            'external_share_max_expiry_hours' => $maxExpiryHours,
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function sameOriginHeaders(): array
    {
        $appUrl = config('app.url');
        $this->assertIsString($appUrl);

        return [
            'Origin' => rtrim($appUrl, '/'),
            'Sec-Fetch-Site' => 'same-origin',
        ];
    }

    /**
     * @return TestResponse<SymfonyResponse>
     */
    private function exchange(ExternalShare $share, string $secret): TestResponse
    {
        return $this->withHeaders($this->sameOriginHeaders())
            ->postJson("/external-shares/{$share->uid}/exchange", [
                'secret' => $secret,
            ])
            ->assertOk();
    }

    /**
     * @return TestResponse<SymfonyResponse>
     */
    private function viewerContent(
        ExternalShare $share,
        string $viewCredential,
        string $windowToken,
    ): TestResponse {
        return $this->withHeaders($this->sameOriginHeaders())
            ->withUnencryptedCookie(self::VIEW_COOKIE, $viewCredential)
            ->post("/external-shares/{$share->uid}/viewer/content", [
                'window_token' => $windowToken,
            ]);
    }

    /**
     * @param array<int|string, Cookie> $cookies
     */
    private function responseCookie(array $cookies, string $name): Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail("Response cookie {$name} was not present.");
    }

    private function cookieValue(Cookie $cookie): string
    {
        $value = $cookie->getValue();
        $this->assertIsString($value);

        return $value;
    }
}
