<!doctype html>
<html class="{{ $themePreference === 'dark' ? 'dark' : '' }}" data-theme="{{ $themePreference }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script nonce="{{ $cspNonce }}" data-theme-bootstrap>
            (() => {
                const root = document.documentElement;
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const useDark = root.dataset.theme === 'dark'
                    || (root.dataset.theme === 'system' && prefersDark);

                root.classList.toggle('dark', useDark);
            })();
        </script>
        <title>{{ isset($title) ? $title . ' / ' : '' }}{{ config('app.name') }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased">
        @if ($authenticatedUser instanceof \App\Models\User)
            <div
                class="af-shell"
                data-app-shell
                @if ($realtimeConfigJson !== null)
                    data-realtime-enabled="true"
                    data-realtime-config="{{ $realtimeConfigJson }}"
                @endif
            >
                <aside class="af-sidebar">
                    <a class="af-brand" href="{{ route('dashboard') }}" aria-label="artifactflow dashboard">
                        <x-brand-mark />
                        <span>
                            <span class="af-brand-name">artifact<span class="af-brand-flow">flow</span></span>
                            <span class="af-brand-caption">Knowledge workspace</span>
                        </span>
                    </a>

                    <nav class="af-primary-nav" data-primary-navigation aria-label="Primary navigation">
                        <a class="af-nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>
                            <span>Overview</span>
                        </a>
                        <a class="af-nav-item {{ request()->routeIs('pages.index', 'pages.show') ? 'is-active' : '' }}" href="{{ route('pages.index') }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4c0-1.1.9-2 2-2Zm8 2v5h5M8 13h8M8 17h8"/></svg>
                            <span>Library</span>
                        </a>
                        <a class="af-nav-item {{ request()->routeIs('pages.create') ? 'is-active' : '' }}" href="{{ $newPageUrl }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                            <span>New page</span>
                        </a>
                        <a class="af-nav-item {{ request()->routeIs('settings.two-factor.*', 'settings.password.*', 'settings.mcp-tokens.*') ? 'is-active' : '' }}" href="{{ route('settings.two-factor.index') }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3 5 6v6c0 4 2.8 7.4 7 9 4.2-1.6 7-5 7-9V6l-7-3Zm-1 11 5-5-1.4-1.4L11 11.2 9.4 9.6 8 11l3 3Z"/></svg>
                            <span>Security</span>
                        </a>
                        @if ($authenticatedUser->is_system_admin)
                            <a class="af-nav-item {{ request()->routeIs('admin.users.*', 'admin.password.*') ? 'is-active' : '' }}" href="{{ route('admin.users.index') }}">
                                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3 4 7v5c0 5 3.4 8.7 8 10 4.6-1.3 8-5 8-10V7l-8-4Zm0 5v4m0 4h.01"/></svg>
                                <span>Administration</span>
                            </a>
                        @endif
                    </nav>

                    <div class="af-sidebar-footer">
                        <a class="af-source-link" href="{{ $sourceUrl }}" rel="noopener noreferrer">Source</a>

                        <div class="af-theme-control">
                            <span class="af-control-label">Theme</span>
                            <form method="POST" action="{{ route('settings.theme') }}" aria-label="Theme preference">
                                @csrf
                                @foreach (['light', 'dark', 'system'] as $theme)
                                    <button
                                        class="{{ $themePreference === $theme ? 'is-active' : '' }}"
                                        name="theme"
                                        type="submit"
                                        value="{{ $theme }}"
                                        aria-label="{{ ucfirst($theme) }} theme"
                                        title="{{ ucfirst($theme) }}"
                                    >
                                        @if ($theme === 'light')
                                            <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                                        @elseif ($theme === 'dark')
                                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 14.2A8 8 0 0 1 9.8 3.5 9 9 0 1 0 20.5 14.2Z"/></svg>
                                        @else
                                            <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/></svg>
                                        @endif
                                    </button>
                                @endforeach
                            </form>
                        </div>

                        <div class="af-user-card">
                            <span class="af-avatar" aria-hidden="true">{{ $userInitial }}</span>
                            <span class="af-user-copy">
                                <strong>{{ $authenticatedUser->name }}</strong>
                                <span>Signed in</span>
                            </span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="af-signout" type="submit" aria-label="Sign out" title="Sign out">
                                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M10 17l5-5-5-5m5 5H3m11-8h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </aside>

                <main class="af-main">
                    {{ $slot }}
                </main>
            </div>

            @if ($passwordResetTokenReviewCount !== null)
                <dialog
                    class="artifactflow-editor-dialog af-compact-dialog"
                    data-auto-open-editor-dialog
                    data-editor-dialog
                    data-password-reset-token-review-notice
                    aria-labelledby="password-reset-token-review-title"
                >
                    <div class="artifactflow-editor-dialog-panel">
                        <div class="af-dialog-header">
                            <div>
                                <p class="af-eyebrow">Account security</p>
                                <h2 id="password-reset-token-review-title">Review your MCP tokens</h2>
                                <p>
                                    Your password was recently reset.
                                    Your {{ $passwordResetTokenReviewCount }} active MCP {{ $passwordResetTokenReviewCount === 1 ? 'token was' : 'tokens were' }} not revoked.
                                    Review {{ $passwordResetTokenReviewCount === 1 ? 'it' : 'them' }} to make sure you recognize every credential.
                                </p>
                            </div>
                            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Dismiss token review notice">Dismiss</button>
                        </div>
                        <div class="flex justify-end p-6">
                            <a class="af-primary-button" href="{{ route('settings.mcp-tokens.index') }}">Review tokens</a>
                        </div>
                    </div>
                </dialog>
            @endif
        @else
            <main class="min-h-screen">
                {{ $slot }}
            </main>
        @endif
    </body>
</html>
