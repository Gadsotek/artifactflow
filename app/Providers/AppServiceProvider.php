<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Events\LogStoredDomainEventDispatch;
use App\Application\Events\StoredDomainEvent;
use App\Application\Identity\TwoFactorPendingChallenge;
use App\Application\Installation\EnvFileWriter;
use App\Application\Mcp\McpAccessTokenAuthenticator;
use App\Application\Mcp\McpEffectiveAuthority;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\PageAccess;
use App\Infrastructure\Security\ProductionSecurityConfiguration;
use App\Models\McpAccessToken;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Policies\PagePolicy;
use App\Policies\WorkspaceInvitationPolicy;
use App\Policies\WorkspaceMembershipPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(InstallationLimitSettings::class);
        $this->app->scoped(McpRequestContext::class);
        $this->app->scoped(McpEffectiveAuthority::class);
        $this->app->scoped(PageAccess::class);

        // The install wizard is the only consumer; it writes APP_ENV and any
        // production boot-gate values into the deployment .env at the project root.
        $this->app->bind(
            EnvFileWriter::class,
            static fn (Application $app): EnvFileWriter => new EnvFileWriter($app->basePath('.env')),
        );
    }

    public function boot(): void
    {
        // Discarding attributes silently is a bug in every environment; lazy
        // loading only throws outside production so a latent N+1 can never
        // take production down. preventAccessingMissingAttributes is left off:
        // it rejects legitimate access to defaulted columns on freshly created
        // models (e.g. User::create() then reading two_factor_confirmed_at).
        Model::preventSilentlyDiscardingAttributes();
        Model::preventLazyLoading(!$this->app->isProduction());

        $this->configureViteHotFile();
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(WorkspaceInvitation::class, WorkspaceInvitationPolicy::class);
        Gate::policy(WorkspaceMembership::class, WorkspaceMembershipPolicy::class);
        // System administration is a global capability, not tied to a model, so it is a
        // Gate ability rather than a policy. Every /admin route hangs off
        // can:administer-system (see routes/web.php), so a new admin route inherits the
        // guard instead of relying on a re-typed is_system_admin check.
        Gate::define('administer-system', static fn (User $user): bool => $user->is_system_admin);
        $this->configureMcpGuard();
        $this->configureRateLimits();
        Event::listen(StoredDomainEvent::class, LogStoredDomainEventDispatch::class);

        if ($this->shouldRunProductionSafetyChecks()) {
            $this->app->make(ProductionSecurityConfiguration::class)->ensureSafe();
        }
    }

    private function configureMcpGuard(): void
    {
        Auth::viaRequest('mcp-token', function (Request $request): ?User {
            $token = app(McpAccessTokenAuthenticator::class)->authenticate($request);

            if (!$token instanceof McpAccessToken) {
                return null;
            }

            $request->attributes->set('mcp_access_token', $token);
            app(McpRequestContext::class)->activate($token, $request->headers->get('Mcp-Agent-Session'));

            return $token->principal;
        });
    }

    private function configureViteHotFile(): void
    {
        $hotFile = config('app.vite_hot_file');

        if (is_string($hotFile) && trim($hotFile) !== '') {
            Vite::useHotFile($hotFile);
        }
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('artifactflow-authenticated', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.authenticated_per_minute', 120))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-page-writes', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.page_writes_per_minute', 30))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-page-presence', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.page_presence_per_minute', 120))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-workspace-creates', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.workspace_creates_per_minute', 10))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-invitations', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.invitations_per_minute', 10))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-invitation-accept', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.invitation_accepts_per_minute', 10))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-password-reset', function (Request $request): Limit {
            $emailInput = $request->input('email', '');
            $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
            $emailKey = $email === '' ? 'missing' : hash('sha256', $email);
            $ip = $request->ip() ?? 'unknown';

            return Limit::perHour($this->configuredRateLimit('rate_limits.password_resets_per_hour', 5))
                ->by('password-reset:' . hash('sha256', $emailKey . '|' . $ip));
        });

        RateLimiter::for('artifactflow-two-factor-challenge', function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $accountKey = $this->twoFactorChallengeAccountKey($request);

            return [
                Limit::perMinute($this->configuredRateLimit('rate_limits.two_factor_challenge_per_minute', 5))
                    ->by('two-factor-account:' . $accountKey),
                Limit::perHour($this->configuredRateLimit('rate_limits.two_factor_challenge_account_per_hour', 30))
                    ->by('two-factor-account-hour:' . $accountKey),
                Limit::perMinute($this->configuredRateLimit('rate_limits.two_factor_challenge_ip_per_minute', 20))
                    ->by('two-factor-ip:' . $ip),
            ];
        });

        RateLimiter::for('artifactflow-markdown-previews', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.markdown_previews_per_minute', 30))
                ->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifactflow-draft-preview-capabilities', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit(
                'rate_limits.draft_preview_capabilities_per_minute',
                30,
            ))->by($this->rateLimitKey($request));
        });

        RateLimiter::for('artifact-previews', function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $limit = $this->configuredRateLimit('rate_limits.artifact_previews_per_minute', 60);

            return [
                Limit::perMinute($limit)->by('artifact-ip:' . $ip),
                Limit::perMinute($limit)->by('artifact-path:' . $ip . ':' . $request->path()),
            ];
        });

        RateLimiter::for('mcp', function (Request $request): Limit {
            $token = $request->attributes->get('mcp_access_token');
            $key = $token instanceof McpAccessToken
                ? 'mcp-token:' . $token->uid
                : 'mcp-ip:' . ($request->ip() ?? 'unknown');

            return Limit::perMinute($this->configuredRateLimit('rate_limits.mcp_per_minute', 60))
                ->by($key);
        });

        RateLimiter::for('artifactflow-admin-step-up', function (Request $request): Limit {
            return Limit::perMinute($this->configuredRateLimit('rate_limits.admin_step_up_per_minute', 5))
                ->by($this->rateLimitKey($request));
        });
    }

    private function configuredRateLimit(string $key, int $default): int
    {
        $value = config($key, $default);
        $limit = is_int($value) || is_string($value) ? (int) $value : $default;

        return max(1, $limit);
    }

    private function rateLimitKey(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return 'user:' . $user->uid;
        }

        return 'ip:' . ($request->ip() ?? 'unknown');
    }

    private function twoFactorChallengeAccountKey(Request $request): string
    {
        $marker = $request->session()->get(TwoFactorPendingChallenge::SESSION_KEY);

        if (is_array($marker)) {
            $userUid = $marker['user_uid'] ?? null;

            if (is_string($userUid) && $userUid !== '') {
                return hash('sha256', $userUid);
            }
        }

        return 'missing:' . ($request->ip() ?? 'unknown');
    }

    private function shouldRunProductionSafetyChecks(): bool
    {
        $environment = $this->normalizedAppEnvironment();

        if (in_array($environment, ['local', 'testing', 'build'], true)) {
            return false;
        }

        if ($environment !== 'production') {
            throw new RuntimeException('APP_ENV must be one of local, testing, build, or production.');
        }

        // The install and doctor commands are the operator's recovery tools. If the
        // boot gate aborted them too, an unsafe production config would lock the
        // operator out of the very commands that provision it (install) or diagnose
        // it (doctor) -- so a single half-finished install could never be completed
        // or even inspected from the CLI. Let those two always boot; the gate still
        // guards every HTTP request and every other console command (queues,
        // scheduler, migrations), which is where an unsafe config must not serve.
        if ($this->runningRecoveryCommand()) {
            return false;
        }

        return true;
    }

    /**
     * Whether this process is running one of the CLI recovery commands that must
     * remain usable even when the production boot gate would otherwise fail closed.
     */
    private function runningRecoveryCommand(): bool
    {
        if (!$this->app->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? null;
        $command = is_array($argv) && isset($argv[1]) && is_string($argv[1]) ? $argv[1] : '';

        return in_array($command, ['artifactflow:install', 'artifactflow:doctor'], true);
    }

    private function normalizedAppEnvironment(): string
    {
        $environment = config('app.env', 'production');

        return is_string($environment) ? strtolower(trim($environment)) : '';
    }
}
