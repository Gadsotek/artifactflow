<x-layouts.app title="Join workspace">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto max-w-xl px-6 py-4">
                <p class="af-eyebrow">Workspace invitation</p>
                <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Join {{ $workspaceName }}</h1>
                <p class="af-page-intro">You've been invited to collaborate as {{ $roleLabel }}.</p>
            </div>
        </header>

        <main class="mx-auto max-w-xl px-6 py-8">
            <div class="space-y-5 border-y border-zinc-200 py-6 dark:border-zinc-800">
                @if ($mode === 'register')
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        Finish setting up your account for
                        <span class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $invitedEmail }}</span>
                        to join {{ $workspaceName }} as {{ $roleLabel }}.
                    </p>

                    <form class="space-y-4" method="POST" action="{{ route('workspace-invitations.join.register', ['invitation' => $invitation->token]) }}">
                        @csrf

                        <div>
                            <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200" for="name">Your name</label>
                            <input
                                id="name"
                                name="name"
                                type="text"
                                value="{{ old('name') }}"
                                autocomplete="name"
                                required
                                autofocus
                                class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                            >
                            @error('name')
                                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">Email</span>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $invitedEmail }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200" for="password">Password</label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                required
                                class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                            >
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">At least 12 characters.</p>
                            @error('password')
                                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200" for="password_confirmation">Confirm password</label>
                            <input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                required
                                class="af-input mt-2 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm outline-none transition"
                            >
                        </div>

                        <button class="af-primary-button" type="submit">Create account &amp; join</button>
                    </form>
                @elseif ($mode === 'sign_in')
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        An account already exists for
                        <span class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $invitedEmail }}</span>.
                        Sign in to join {{ $workspaceName }}.
                    </p>
                    <a class="af-primary-button inline-block" href="{{ route('login') }}">Sign in to join</a>
                @else
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        This invitation is for
                        <span class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $invitedEmail }}</span>,
                        which is a different account than the one you're signed in with. Sign out and open the
                        invitation link again to accept it.
                    </p>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="af-primary-button" type="submit">Sign out</button>
                    </form>
                @endif
            </div>
        </main>
    </div>
</x-layouts.app>
