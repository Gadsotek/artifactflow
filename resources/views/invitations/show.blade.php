<x-layouts.app title="Workspace invitation">
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
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    Accepting adds your account to
                    <span class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $workspaceName }}</span>
                    as {{ $roleLabel }}.
                    @if ($expiresAt)
                        This invitation expires on {{ $expiresAt->format('Y-m-d H:i T') }}.
                    @endif
                </p>

                <form method="POST" action="{{ route('workspace-invitations.accept', $invitation) }}">
                    @csrf

                    <div class="flex items-center gap-3">
                        <button class="af-primary-button" type="submit">Accept invitation</button>
                        <a class="text-sm font-medium text-zinc-700 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-zinc-50" href="{{ route('dashboard') }}">
                            Not now
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</x-layouts.app>
