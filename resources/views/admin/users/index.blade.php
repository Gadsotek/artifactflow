<x-layouts.app title="User administration">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div>
                    <p class="af-eyebrow">System Admin</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">User administration</h1>
                    <p class="af-page-intro">Provision verified users and inspect deployment boundaries.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a class="af-secondary-button" href="{{ route('admin.settings.edit') }}">Storage and limits</a>
                    <a class="af-secondary-button" href="{{ route('dashboard') }}">Back to overview</a>
                </div>
            </div>
        </header>

        <main class="af-admin-page mx-auto max-w-6xl space-y-8 px-6 py-8">
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

            <section class="grid gap-6 lg:grid-cols-[22rem_1fr]">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Create login user</h2>
                    <form class="mt-4 space-y-4" method="POST" action="{{ route('admin.users.store') }}">
                        @csrf
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Name</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="name" type="text" maxlength="255" value="{{ old('name') }}" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Email</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="email" type="email" maxlength="255" value="{{ old('email') }}" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Temporary password</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="password" type="password" minlength="12" autocomplete="new-password" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirm password</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
                        </label>
                        <p class="text-xs leading-5 text-zinc-500">Creates a verified non-admin user and their personal workspace. Passwords are never shown or logged.</p>
                        <button class="af-primary-button" type="submit">Create user</button>
                    </form>
                </div>

                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Users</h2>
                    <div class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                        @foreach ($users as $managedUser)
                            <div class="flex items-center justify-between gap-4 py-3">
                                <div>
                                    <p class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $managedUser->name }}</p>
                                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $managedUser->email }}</p>
                                </div>
                                <div class="text-right text-xs text-zinc-500">
                                    <p>{{ $managedUser->isSystemAdmin ? 'System Admin' : 'User' }}</p>
                                    <p>Created {{ $managedUser->createdAt->toDateString() }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Deployment settings</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Installation-wide security settings remain environment-managed and read-only in the application.</p>
                <dl class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                    <div><dt class="text-zinc-500">Application URL</dt><dd class="break-all font-medium text-zinc-900 dark:text-zinc-100">{{ $appUrl }}</dd></div>
                    <div><dt class="text-zinc-500">Artifact URL</dt><dd class="break-all font-medium text-zinc-900 dark:text-zinc-100">{{ $artifactUrl }}</dd></div>
                    <div><dt class="text-zinc-500">Source URL</dt><dd class="break-all font-medium text-zinc-900 dark:text-zinc-100">{{ $sourceUrl }}</dd></div>
                </dl>
            </section>
        </main>
    </div>
</x-layouts.app>
