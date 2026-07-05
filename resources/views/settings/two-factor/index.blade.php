<x-layouts.app title="Account security">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p class="af-eyebrow">Account security</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Two-factor authentication</h1>
                    <p class="af-page-intro">Protect your account with an authenticator app and single-use recovery codes.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a class="af-secondary-button" href="{{ route('settings.mcp-tokens.index') }}">MCP tokens</a>
                    <a class="af-secondary-button" href="{{ route('dashboard') }}">Back to overview</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            @if (session('status'))
                <div class="af-callout">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950 dark:bg-red-950 dark:text-red-100">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if ($recoveryCodes !== [])
                <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                    <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Recovery codes</h2>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach ($recoveryCodes as $code)
                            <code class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-50">{{ $code }}</code>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                @if ($user->hasEnabledTwoFactor())
                    <div>
                        <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Two-factor authentication is enabled</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Login requires an authenticator code or a recovery code. Disabling it or regenerating recovery codes requires a current authenticator or recovery code.</p>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <form class="space-y-3" method="POST" action="{{ route('settings.two-factor.disable') }}">
                            @csrf
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Authenticator or recovery code</span>
                                <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
                            </label>
                            <button class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800" type="submit">Disable two-factor</button>
                        </form>

                        <form class="space-y-3" method="POST" action="{{ route('settings.two-factor.recovery-codes') }}">
                            @csrf
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Authenticator or recovery code</span>
                                <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
                            </label>
                            <button class="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" type="submit">Regenerate recovery codes</button>
                        </form>
                    </div>
                @else
                    <div class="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Two-factor authentication is not enabled</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Use an authenticator app to add a second sign-in step.</p>
                        </div>
                        <form method="POST" action="{{ route('settings.two-factor.enroll') }}">
                            @csrf
                            <button class="af-primary-button" type="submit">Start enrollment</button>
                        </form>
                    </div>

                    @if ($pendingSecret !== null)
                        <div class="mt-6 grid gap-6 md:grid-cols-[14rem_minmax(0,1fr)]">
                            @if ($qrCodeDataUri !== null)
                                <img class="h-56 w-56 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-800" src="{{ $qrCodeDataUri }}" alt="Authenticator app QR code">
                            @endif
                            <form class="space-y-4" method="POST" action="{{ route('settings.two-factor.confirm') }}">
                                @csrf
                                <div>
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Authenticator key</p>
                                    <code class="mt-2 block break-all rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-50">{{ $pendingSecret }}</code>
                                </div>
                                <label class="block">
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Authentication code</span>
                                    <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
                                </label>
                                <button class="af-primary-button" type="submit">Confirm and enable</button>
                            </form>
                        </div>
                    @endif
                @endif
            </section>

            <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Trusted devices</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Devices remembered at login can skip only the authenticator-code prompt.</p>
                    </div>
                    @if ($trustedDevices->isNotEmpty())
                        <form method="POST" action="{{ route('settings.two-factor.trusted-devices.destroy-all') }}">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" type="submit">Revoke all</button>
                        </form>
                    @endif
                </div>

                <div class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    @forelse ($trustedDevices as $trustedDevice)
                        <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div>
                                <p class="font-medium text-zinc-950 dark:text-zinc-50">{{ $trustedDevice->label }}</p>
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Last used {{ $trustedDevice->last_used_at?->diffForHumans() ?? 'never' }} · Expires {{ $trustedDevice->expires_at->toFormattedDateString() }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('settings.two-factor.trusted-devices.destroy', $trustedDevice) }}">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" type="submit">Revoke</button>
                            </form>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-zinc-600 dark:text-zinc-400">No trusted devices.</p>
                    @endforelse
                </div>
            </section>
        </main>
    </div>
</x-layouts.app>
