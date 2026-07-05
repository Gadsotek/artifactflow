@use('App\Domain\Identity\WorkspaceRole')
@use('App\Domain\PageCatalog\PageAccessMode')
<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-access-dialog" aria-labelledby="page-access-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Authorization</p>
                <h2 id="page-access-dialog-title">Access overrides</h2>
                <p>Workspace inheritance is the default. Restricted pages disclose grants only to authorized access managers.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close access controls">Close</button>
        </div>
        <div class="af-dialog-scroll af-dialog-columns">
            <section>
                <h3>Workspace inheritance</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    {{ $page->access_mode === PageAccessMode::Inherited
                        ? 'Workspace members inherit their workspace role.'
                        : 'Restricted to the owner, workspace Admins, and explicit access grants.' }}
                </p>
                @if ($canManageAccess)
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('pages.access-mode.update', $page) }}">
                        @csrf
                        @method('PUT')
                        <label class="block">
                            <span class="text-sm font-medium">Access mode</span>
                            <select class="mt-2 w-full" name="access_mode">
                                <option value="{{ PageAccessMode::Inherited->value }}" @selected($page->access_mode === PageAccessMode::Inherited)>Inherit workspace access</option>
                                <option value="{{ PageAccessMode::Restricted->value }}" @selected($page->access_mode === PageAccessMode::Restricted)>Restrict to explicit access</option>
                            </select>
                        </label>
                        <button class="af-secondary-button w-full" type="submit">Update access mode</button>
                    </form>
                @endif

                @if ($canManageAccess)
                    <div class="mt-6">
                        <h3>Explicit grants</h3>
                        <div class="mt-3 space-y-2">
                            @forelse ($pageAccessGrants as $grant)
                                <div class="af-access-grant">
                                    <div><strong>{{ $grant->subjectType->value }}</strong><span>{{ $grant->subjectLabel }}</span><em>{{ $grant->role->value }}</em></div>
                                    <form method="POST" action="{{ route('pages.access.destroy', [$page, $grant->grantUid]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="af-danger-link" type="submit">Revoke access</button>
                                    </form>
                                </div>
                            @empty
                                <p class="af-quiet-empty">No explicit access grants.</p>
                            @endforelse
                        </div>
                    </div>
                @endif

            </section>

            @if ($canManageAccess)
                <section>
                    <h3>Grant user access</h3>
                    <form
                        class="mt-4 space-y-3"
                        method="POST"
                        action="{{ route('pages.access.store', $page) }}"
                        data-known-user-picker
                        data-known-user-value-key="email"
                        data-search-url="{{ route('pages.access-users.search', $page) }}"
                    >
                        @csrf
                        <input name="subject_type" type="hidden" value="user">
                        <p class="text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                            Reader and Editor grants apply only to this page and do not require workspace membership. Page Admin grants require workspace membership.
                        </p>
                        <div>
                            <label class="block">
                                <span class="text-sm font-medium">User</span>
                                <input
                                    class="mt-2 w-full"
                                    name="user_email"
                                    type="search"
                                    value="{{ old('user_email') }}"
                                    autocomplete="off"
                                    placeholder="Search by name or email"
                                    data-known-user-search
                                    data-known-user-value
                                    aria-controls="page-access-user-results"
                                    aria-expanded="false"
                                    aria-autocomplete="list"
                                    role="combobox"
                                    required
                                >
                            </label>
                            <ul id="page-access-user-results" class="af-collaborator-results mt-2 hidden" role="listbox" data-known-user-results></ul>
                            <p class="af-collaborator-empty mt-2 hidden text-sm text-zinc-500 dark:text-zinc-400" data-known-user-empty>No matching eligible people.</p>
                        </div>
                        <p class="af-collaborator-selected hidden text-sm text-zinc-700 dark:text-zinc-300" data-known-user-selected>
                            Granting access to <span class="font-semibold text-zinc-950 dark:text-zinc-50" data-known-user-selected-label></span>.
                        </p>
                        <label class="block">
                            <span class="text-sm font-medium">Role</span>
                            <select class="mt-2 w-full" name="role">
                                @foreach (WorkspaceRole::cases() as $grantRole)
                                    <option value="{{ $grantRole->value }}">{{ ucfirst($grantRole->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="af-primary-button w-full" type="submit" data-known-user-submit>Grant user access</button>
                    </form>

                    @if ($pageAccessWorkspaceTargets !== [])
                        <form class="mt-6 space-y-3 border-t border-zinc-200 pt-5 dark:border-zinc-800" method="POST" action="{{ route('pages.access.store', $page) }}">
                            @csrf
                            <input name="subject_type" type="hidden" value="workspace">
                            <h3>Grant workspace access</h3>
                            <label class="block">
                                <span class="text-sm font-medium">Workspace</span>
                                <select class="mt-2 w-full" name="workspace_uid" required>
                                    @foreach ($pageAccessWorkspaceTargets as $workspaceTarget)
                                        <option value="{{ $workspaceTarget->uid }}">{{ $workspaceTarget->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Role</span>
                                <select class="mt-2 w-full" name="role">
                                    <option value="{{ WorkspaceRole::Reader->value }}">Reader</option>
                                </select>
                            </label>
                            <button class="af-secondary-button w-full" type="submit">Grant workspace access</button>
                        </form>
                    @endif
                </section>
            @endif
        </div>
    </div>
</dialog>
