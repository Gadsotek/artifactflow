<x-layouts.app title="Storage and limits">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p class="af-eyebrow">System Admin</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Storage and limits</h1>
                    <p class="af-page-intro">Tune installation-wide page limits and monitor storage consumption.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a class="af-secondary-button" href="{{ route('admin.users.index') }}">Users</a>
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

            <section class="grid gap-4 md:grid-cols-4">
                <div class="border-y border-zinc-200 py-4 dark:border-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Used storage</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $usage->summary->usedBytesLabel }}</p>
                </div>
                <div class="border-y border-zinc-200 py-4 dark:border-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Workspaces</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $usage->summary->workspaceCount }}</p>
                </div>
                <div class="border-y border-zinc-200 py-4 dark:border-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Pages</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $usage->summary->pageCount }}</p>
                </div>
                <div class="border-y border-zinc-200 py-4 dark:border-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Versions</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $usage->summary->versionCount }}</p>
                </div>
            </section>

            <section class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <div>
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Workspace usage</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Sorted by stored page-version bytes.</p>
                        </div>
                    </div>
                    <div class="af-usage-list mt-4 divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                        @forelse ($usage->workspaces as $workspaceUsage)
                            <div class="py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $workspaceUsage->name }}</p>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ ucfirst($workspaceUsage->type) }} workspace &middot; {{ $workspaceUsage->pageCount }} pages &middot; {{ $workspaceUsage->versionCount }} versions</p>
                                    </div>
                                    <p class="text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        <span class="block whitespace-nowrap">{{ $workspaceUsage->usageLabel() }}</span>
                                        <span class="block text-xs text-zinc-500">{{ $workspaceUsage->percentUsedLabel }} used</span>
                                    </p>
                                </div>
                                <div
                                    class="af-progress-meter mt-3"
                                    role="progressbar"
                                    aria-label="{{ $workspaceUsage->name }} storage usage"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="{{ $workspaceUsage->ariaPercent }}"
                                >
                                    <div class="af-progress-fill h-full" style="--af-progress-value: {{ $workspaceUsage->progressPercent }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="py-4 text-sm text-zinc-600 dark:text-zinc-400">No workspaces have been created yet.</p>
                        @endforelse
                    </div>

                    <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-zinc-500">Largest pages</h2>
                    <div class="af-usage-list mt-4 divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                        @forelse ($usage->pages as $pageUsage)
                            <div class="py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $pageUsage->title }}</p>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $pageUsage->workspaceName }} &middot; {{ $pageUsage->versionCount }} versions</p>
                                    </div>
                                    <p class="text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        <span class="block whitespace-nowrap">{{ $pageUsage->usageLabel() }}</span>
                                        <span class="block text-xs text-zinc-500">{{ $pageUsage->percentUsedLabel }} used</span>
                                    </p>
                                </div>
                                <div
                                    class="af-progress-meter mt-3"
                                    role="progressbar"
                                    aria-label="{{ $pageUsage->title }} storage usage"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="{{ $pageUsage->ariaPercent }}"
                                >
                                    <div class="h-full bg-amber-600" style="--af-progress-value: {{ $pageUsage->progressPercent }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="py-4 text-sm text-zinc-600 dark:text-zinc-400">No pages have been created yet.</p>
                        @endforelse
                    </div>
                </div>

                <aside class="lg:sticky lg:top-24 lg:self-start">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Limit settings</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Enter readable sizes; ArtifactFlow stores the exact byte values.</p>
                    <form class="mt-4 space-y-4" method="POST" action="{{ route('admin.settings.update') }}">
                        @csrf
                        @method('PUT')
                        @foreach ($limitItems as $limit)
                            <label class="block">
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $limit->label }}</span>
                                @if ($limit->usesByteUnits())
                                    <span class="mt-1 grid grid-cols-[minmax(0,1fr)_5.5rem] gap-2">
                                        <input class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="{{ $limit->name }}_amount" type="number" min="0.001" step="0.001" value="{{ old($limit->name . '_amount', $limit->displayAmount) }}" required>
                                        <select class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="{{ $limit->name }}_unit" required>
                                            @foreach (['B', 'KiB', 'MiB', 'GiB'] as $unitOption)
                                                <option value="{{ $unitOption }}" @selected(old($limit->name . '_unit', $limit->displayUnit) === $unitOption)>{{ $unitOption }}</option>
                                            @endforeach
                                        </select>
                                    </span>
                                    <span class="mt-1 block text-xs text-zinc-500">{{ $limit->description }} Current: {{ $limit->displayValue }}. Maximum: {{ $limit->maxDisplayValue }}.</span>
                                @else
                                    <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="{{ $limit->name }}" type="number" min="1" max="{{ $limit->maxValue }}" step="1" value="{{ old($limit->name, $limit->value) }}" required>
                                    <span class="mt-1 block text-xs text-zinc-500">{{ $limit->description }} Current: {{ $limit->displayValue }} {{ $limit->unit !== 'bytes' ? $limit->unit : '' }}</span>
                                @endif
                            </label>
                        @endforeach
                        <div class="border-y border-zinc-200 py-4 dark:border-zinc-800">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Two-factor enforcement</p>
                            <label class="mt-3 flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                                <input name="two_factor_required_for_system_admins" type="hidden" value="0">
                                <input
                                    class="af-checkbox mt-1 h-4 w-4 rounded border-zinc-300"
                                    name="two_factor_required_for_system_admins"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('two_factor_required_for_system_admins', $limitValues->twoFactorRequiredForSystemAdmins ? '1' : '0') === '1')
                                >
                                <span>Require two-factor authentication for System Admins</span>
                            </label>
                            <label class="mt-3 flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                                <input name="two_factor_required_for_all_users" type="hidden" value="0">
                                <input
                                    class="af-checkbox mt-1 h-4 w-4 rounded border-zinc-300"
                                    name="two_factor_required_for_all_users"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('two_factor_required_for_all_users', $limitValues->twoFactorRequiredForAllUsers ? '1' : '0') === '1')
                                >
                                <span>Require two-factor authentication for all users</span>
                            </label>
                        </div>
                        <div class="border-b border-zinc-200 pb-4 dark:border-zinc-800">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Realtime collaboration</p>
                            <label class="mt-3 flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                                <input name="realtime_enabled" type="hidden" value="0">
                                <input
                                    class="af-checkbox mt-1 h-4 w-4 rounded border-zinc-300"
                                    name="realtime_enabled"
                                    type="checkbox"
                                    value="1"
                                    @checked(old('realtime_enabled', $limitValues->realtimeEnabled ? '1' : '0') === '1')
                                >
                                <span>Enable Reverb-backed realtime features</span>
                            </label>
                        </div>
                        <button class="af-primary-button" type="submit">Save limits</button>
                    </form>
                </aside>
            </section>
        </main>
    </div>
</x-layouts.app>
