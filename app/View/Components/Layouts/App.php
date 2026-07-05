<?php

declare(strict_types=1);

namespace App\View\Components\Layouts;

use App\Application\Administration\RealtimeConfiguration;
use App\Models\Page;
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

    /**
     * @throws JsonException
     */
    public function __construct(
        AuthFactory $auth,
        RealtimeConfiguration $realtime,
        Request $request,
        UrlGenerator $url,
        Vite $vite,
        public ?string $title = null,
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
        $routePage = $request->route('page');
        $this->newPageUrl = $routePage instanceof Page
            ? $url->route('pages.create', [
                'workspace_uid' => $routePage->workspace_uid,
                'parent_page_uid' => $routePage->uid,
            ])
            : $url->route('pages.create');
        $realtimeConfig = $this->authenticatedUser instanceof User
            ? $realtime->clientConfig()
            : null;
        $this->realtimeConfigJson = $realtimeConfig === null
            ? null
            : json_encode($realtimeConfig, JSON_THROW_ON_ERROR);
    }

    public function render(): View
    {
        return view('components.layouts.app');
    }
}
