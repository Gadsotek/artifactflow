<x-layouts.app title="Confirm password">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto max-w-xl px-6 py-4">
                <p class="af-eyebrow">Account security</p>
                <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Confirm password</h1>
                <p class="af-page-intro">Re-enter your password before changing two-factor authentication settings.</p>
            </div>
        </header>

        <main class="mx-auto max-w-xl px-6 py-8">
            @if (session('status'))
                <div class="af-callout mb-5">
                    {{ session('status') }}
                </div>
            @endif

            <form class="space-y-5 border-y border-zinc-200 py-6 dark:border-zinc-800" method="POST" action="{{ route('settings.password.confirm.store') }}">
                @csrf

                <label class="block">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Password</span>
                    <input
                        class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        autofocus
                    >
                </label>

                @error('password')
                    <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="flex items-center gap-3">
                    <button class="af-primary-button" type="submit">
                        Confirm password
                    </button>
                    <a class="text-sm font-medium text-zinc-700 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-zinc-50" href="{{ route('settings.two-factor.index') }}">
                        Back to security
                    </a>
                </div>
            </form>
        </main>
    </div>
</x-layouts.app>
