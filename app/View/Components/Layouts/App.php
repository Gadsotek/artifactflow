<?php

declare(strict_types=1);

namespace App\View\Components\Layouts;

use App\Application\Administration\RealtimeConfiguration;
use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceNavigationItem;
use App\Http\Support\PasswordResetTokenReviewNotice;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use JsonException;

/**
 * Class-based layout component so the shell template stays presentation-only:
 * all data resolution (auth user, theme, CSP nonce, realtime client config)
 * happens here instead of a @php block inside the Blade view.
 */
final class App extends Component
{
    public ?User $authenticatedUser;

    public string $themePreference;

    public ?string $userInitial;

    public ?string $cspNonce;

    public string $sourceUrl;

    public string $newPageUrl;

    public ?string $realtimeConfigJson;

    public ?int $passwordResetTokenReviewCount;

    /** @var list<WorkspaceNavigationItem> */
    public array $navigationWorkspaces;

    public ?string $navigationStorageKey;

    /**
     * @throws JsonException
     */
    public function __construct(
        AuthFactory $auth,
        RealtimeConfiguration $realtime,
        Request $request,
        UrlGenerator $url,
        Vite $vite,
        WorkspaceContext $workspaceContext,
        public ?string $title = null,
        public ?string $turnstileScriptUrl = null,
    ) {
        $user = $auth->guard()->user();
        $this->authenticatedUser = $user instanceof User ? $user : null;
        $defaultTheme = config('app.default_theme', 'system');
        $this->themePreference = $this->authenticatedUser instanceof User
            ? $this->authenticatedUser->theme_preference->value
            : (is_string($defaultTheme) ? $defaultTheme : 'system');
        $this->userInitial = $this->authenticatedUser instanceof User
            ? mb_strtoupper(mb_substr($this->authenticatedUser->name, 0, 1))
            : null;
        $this->cspNonce = $vite->cspNonce();
        $sourceUrl = config('app.source_url');
        $this->sourceUrl = is_string($sourceUrl) ? $sourceUrl : '';
        $this->newPageUrl = $url->route('pages.create');
        $this->navigationStorageKey = $this->authenticatedUser instanceof User
            ? hash('sha256', $this->authenticatedUser->uid) : null;
        $this->navigationWorkspaces = $this->authenticatedUser instanceof User
            ? $workspaceContext->itemsFor($this->authenticatedUser)
            : [];
        $realtimeConfig = $this->authenticatedUser instanceof User
            ? $realtime->clientConfig()
            : null;
        $this->realtimeConfigJson = $realtimeConfig === null
            ? null
            : json_encode($realtimeConfig, JSON_THROW_ON_ERROR);
        $tokenReviewCount = $request->session()->get(PasswordResetTokenReviewNotice::SESSION_KEY);
        $this->passwordResetTokenReviewCount = is_int($tokenReviewCount) && $tokenReviewCount > 0
            ? $tokenReviewCount
            : null;
    }

    public function render(): View
    {
        return view('components.layouts.app');
    }
}
