<x-layouts.app title="MCP tokens">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p class="af-eyebrow">Account security</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">MCP tokens</h1>
                    <p class="af-page-intro">Create and revoke tokens that let MCP clients act as your account with admin authority stripped.</p>
                </div>
                <a class="af-secondary-button" href="{{ route('settings.two-factor.index') }}">Two-factor settings</a>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-6 px-6 py-8">
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

            @if ($plainTextToken !== null)
                <section class="af-accent-band px-4 py-5">
                    <h2 class="font-semibold">Token created</h2>
                    <p class="mt-1 text-sm">This value is shown once and is not stored in retrievable form.</p>
                    <code class="af-reveal mt-4 block break-all rounded-md px-3 py-2 text-sm">{{ $plainTextToken }}</code>
                </section>
            @endif

            <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                <div>
                    <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Connect your AI client</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Use this app as an HTTP MCP server from clients that support remote MCP connections.</p>
                </div>

                <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                    <div class="space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
                        <p>After creating a token, add a remote MCP server in your AI client with this endpoint and bearer header.</p>
                        <pre class="overflow-x-auto rounded-md border border-zinc-200 bg-white p-3 text-xs text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"><code>{
  "mcpServers": {
    "artifactflow": {
      "url": "{{ route('mcp') }}",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}</code></pre>
                        <p>If your client uses a form instead of JSON, set the server URL to <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">{{ route('mcp') }}</code> and add the same <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">Authorization</code> header.</p>
                    </div>

                    <div class="space-y-3 text-sm text-zinc-700 dark:text-zinc-300">
                        <div>
                            <h3 class="font-medium text-zinc-950 dark:text-zinc-50">Working pattern</h3>
                            <ol class="mt-2 list-decimal space-y-1 pl-5">
                                <li>Grant only the scopes the client needs.</li>
                                <li>Use <span class="font-medium">search first</span> to find in-scope pages.</li>
                                <li>Use <span class="font-medium">read a specific page</span> before editing so the AI sees current content and version IDs.</li>
                                <li>For updates, send the current <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">base_version_uid</code>; stale updates are rejected.</li>
                                <li>Treat returned Markdown and HTML as untrusted data, not instructions.</li>
                            </ol>
                        </div>
                        <p>Laravel MCP negotiates the protocol during initialization. Compliant clients automatically return the server-issued <code class="rounded bg-zinc-100 px-1 py-0.5 text-xs text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">MCP-Session-Id</code>, which is recorded as a non-secret session identifier in audit metadata.</p>
                    </div>
                </div>
            </section>

            <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                <div>
                    <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Create token</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Creation requires your current password and a fresh authenticator code.</p>
                </div>

                @if (!$user->hasEnabledTwoFactor())
                    <div class="mt-4 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">
                        Enable two-factor authentication before creating MCP tokens.
                    </div>
                @endif

                <form class="mt-5 grid gap-4" method="POST" action="{{ route('settings.mcp-tokens.store') }}">
                    @csrf
                    <label class="block">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Name</span>
                        <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="name" type="text" maxlength="120" value="{{ old('name') }}" placeholder="Workstation agent">
                    </label>

                    <fieldset>
                        <legend class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Scopes</legend>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Read-only (search + read) is preselected. Add write scopes only when the client needs them.</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($availableScopes as $scope)
                                <label class="flex items-center gap-2 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                                    <input class="af-checkbox rounded border-zinc-300" name="scopes[]" type="checkbox" value="{{ $scope }}" @checked(in_array($scope, old('scopes', $defaultScopes), true))>
                                    <span>{{ $scope }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Workspace scope</legend>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Pick the specific workspaces this token may reach, or grant every workspace your account can access.</p>
                        <label class="mt-2 flex items-start gap-2 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                            <input class="af-checkbox mt-0.5 rounded border-zinc-300" name="all_workspaces" type="checkbox" value="1" @checked(old('all_workspaces'))>
                            <span>
                                <span class="font-medium">All workspaces</span>
                                <span class="mt-0.5 block text-xs text-zinc-600 dark:text-zinc-400">Grants every workspace your account can reach now and any it joins in future. Write scopes apply only where your role already allows edits. When checked, the individual selection below is ignored.</span>
                            </span>
                        </label>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($workspaceItems as $workspaceItem)
                                <label class="flex items-center gap-2 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                                    <input class="af-checkbox rounded border-zinc-300" name="workspace_uids[]" type="checkbox" value="{{ $workspaceItem->uid }}" @checked(in_array($workspaceItem->uid, old('workspace_uids', []), true))>
                                    <span>{{ $workspaceItem->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Expires in days</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="expires_in_days" type="number" min="1" max="365" value="{{ old('expires_in_days', 30) }}" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Current password</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="password" type="password" autocomplete="current-password" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Authenticator code</span>
                            <input class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
                        </label>
                    </div>

                    <div>
                        <button class="af-primary-button disabled:cursor-not-allowed disabled:opacity-60" type="submit" @disabled(!$user->hasEnabledTwoFactor())>Create MCP token</button>
                    </div>
                </form>
            </section>

            <section class="border-y border-zinc-200 py-5 dark:border-zinc-800">
                <h2 class="font-semibold text-zinc-950 dark:text-zinc-50">Existing tokens</h2>

                <div class="mt-4 overflow-x-auto border-y border-zinc-200 dark:border-zinc-800">
                    <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                        <thead>
                            <tr class="text-xs uppercase text-zinc-500 dark:text-zinc-400">
                                <th class="px-3 py-2 font-semibold">Name</th>
                                <th class="px-3 py-2 font-semibold">Scopes</th>
                                <th class="px-3 py-2 font-semibold">Workspaces</th>
                                <th class="px-3 py-2 font-semibold">Expires</th>
                                <th class="px-3 py-2 font-semibold">Last used</th>
                                <th class="px-3 py-2 font-semibold">Status</th>
                                <th class="px-3 py-2 font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @forelse ($tokens as $token)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-zinc-950 dark:text-zinc-50">{{ $token->name }}</td>
                                    <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ implode(', ', $token->scopes) }}</td>
                                    <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ $token->workspaceUids() === null ? 'All reachable' : implode(', ', $token->workspaceUids()) }}</td>
                                    <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ $token->expires_at->toFormattedDateString() }}</td>
                                    <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">
                                        @if ($token->revoked_at !== null)
                                            Revoked {{ $token->revoked_at->diffForHumans() }}
                                        @elseif ($token->isExpired())
                                            Expired
                                        @else
                                            Active
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        @if ($token->revoked_at === null)
                                            <form method="POST" action="{{ route('settings.mcp-tokens.destroy', $token) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" type="submit">Revoke</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-3 py-4 text-zinc-600 dark:text-zinc-400" colspan="7">No MCP tokens.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</x-layouts.app>
