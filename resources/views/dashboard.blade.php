<x-layouts.app title="Dashboard">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div>
                    <p class="af-eyebrow">Knowledge workspace</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Dashboard</h1>
                    <p class="af-page-intro">Move between knowledge, people, and workspace administration without losing context.</p>
                </div>
                <a class="af-primary-button" href="{{ route('pages.create', $currentWorkspaceUid === null ? [] : ['workspace_uid' => $currentWorkspaceUid]) }}">Create page</a>
            </div>
        </header>

        <div class="af-page-grid mx-auto grid max-w-7xl gap-8 px-6 py-8 lg:grid-cols-[18rem_1fr]">
            <aside class="af-context-panel">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Workspaces</h2>
                    <button
                        class="af-icon-button"
                        data-open-editor-dialog="workspace-create-dialog"
                        type="button"
                        aria-label="Create workspace"
                        title="Create workspace"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
                <div class="mt-3 space-y-2">
                    @foreach ($workspaces as $workspace)
                        <form method="POST" action="{{ route('workspaces.switch', $workspace->uid) }}">
                            @csrf
                            <button
                                class="flex w-full items-center justify-between rounded-md border px-3 py-2 text-left text-sm transition {{ $workspace->uid === $currentWorkspaceUid ? 'af-option-active' : 'border-zinc-200 bg-white text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100' }}"
                                type="submit"
                            >
                                <span class="font-medium">{{ $workspace->name }}</span>
                                <span class="text-xs uppercase tracking-wide">{{ $workspace->role->value }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            </aside>

            <section class="space-y-6">
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

                @if ($pendingInvitations !== [])
                    <section class="af-incoming-invitations border-y border-zinc-200 py-5 dark:border-zinc-800">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Workspace invitations</h2>
                        <div class="mt-3 divide-y divide-zinc-200 dark:divide-zinc-800">
                            @foreach ($pendingInvitations as $invitation)
                                <div class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $invitation->workspaceName }}</p>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">Invited as {{ $invitation->role->value }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('workspace-invitations.accept', $invitation->uid) }}">
                                        @csrf
                                        <button class="af-primary-button" type="submit">Accept invitation</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="af-workspace-tabs" data-workspace-tabs>
                    <div class="af-workspace-tab-list" role="tablist" aria-label="Workspace dashboard">
                        @foreach ([
                            'overview' => ['label' => 'Overview', 'count' => null],
                            'members' => ['label' => 'Members', 'count' => $workspaceMemberPage->total],
                            'settings' => ['label' => 'Settings', 'count' => null],
                        ] as $tabName => $tab)
                            <button
                                aria-controls="workspace-{{ $tabName }}-panel"
                                aria-selected="{{ $activeWorkspaceTab === $tabName ? 'true' : 'false' }}"
                                class="{{ $activeWorkspaceTab === $tabName ? 'is-active' : '' }}"
                                data-workspace-tab="{{ $tabName }}"
                                role="tab"
                                tabindex="{{ $activeWorkspaceTab === $tabName ? '0' : '-1' }}"
                                type="button"
                            >
                                {{ $tab['label'] }}
                                @if ($tab['count'] !== null)
                                    <span>{{ $tab['count'] }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    <section
                        id="workspace-overview-panel"
                        data-workspace-panel="overview"
                        role="tabpanel"
                        @if ($activeWorkspaceTab !== 'overview') hidden @endif
                    >
                        <div class="af-workspace-hero">
                            <div>
                                <p class="af-eyebrow">Current workspace</p>
                                <h2>
                                    @foreach ($workspaces as $workspace)
                                        @if ($workspace->uid === $currentWorkspaceUid)
                                            {{ $workspace->name }}
                                        @endif
                                    @endforeach
                                </h2>
                                <p>Search what your team already knows before starting something new.</p>
                            </div>
                            <form method="GET" action="{{ route('pages.index') }}">
                                <input name="workspace_uid" type="hidden" value="{{ $currentWorkspaceUid ?? 'all' }}">
                                <label>
                                    <span class="sr-only">Search pages</span>
                                    <input name="q" type="search" placeholder="Search pages, artifacts, tags…">
                                </label>
                                <button class="af-primary-button" type="submit">Search pages</button>
                            </form>
                        </div>

                        <section class="af-metric-grid">
                            <div>
                                <span>Draft pages</span>
                                <strong>{{ $discoverySummary->draftPageCount }}</strong>
                                <p>Work still taking shape</p>
                            </div>
                            <div>
                                <span>Deprecated pages</span>
                                <strong>{{ $discoverySummary->deprecatedPageCount }}</strong>
                                <p>Guidance marked as outdated</p>
                            </div>
                            <div>
                                <span>Popular tags</span>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @forelse ($discoverySummary->popularTags as $popularTag)
                                        <span class="af-soft-chip">{{ $popularTag->name }} · {{ $popularTag->pageCount }}</span>
                                    @empty
                                        <span class="text-sm text-zinc-500">No tags yet.</span>
                                    @endforelse
                                </div>
                            </div>
                        </section>

                        <section class="af-overview-categories">
                            <div class="af-section-title-row">
                                <div>
                                    <p class="af-eyebrow">Category summary</p>
                                    <h2>Workspace categories</h2>
                                </div>
                                @if ($canCreateCategoriesInCurrentWorkspace && $currentWorkspaceUid !== null)
                                    <button
                                        class="af-icon-button"
                                        data-open-editor-dialog="category-create-dialog"
                                        type="button"
                                        aria-label="Create category"
                                        title="Create category"
                                    >
                                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                                    </button>
                                @endif
                            </div>
                            @if ($categories === [])
                                <p class="af-quiet-empty mt-3">No categories yet.</p>
                            @else
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($categories as $category)
                                        <span class="af-soft-chip">{{ $category->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        @if ($pages === [])
                            <div class="af-empty-state">
                                <h3 class="text-lg font-semibold text-zinc-950 dark:text-zinc-50">No pages yet</h3>
                                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">Create the first Markdown page or HTML artifact for this workspace.</p>
                                <div class="mt-5 flex flex-wrap gap-3">
                                    <a class="af-primary-button" href="{{ route('pages.create', $currentWorkspaceUid === null ? [] : ['workspace_uid' => $currentWorkspaceUid]) }}">Create page</a>
                                    @if ($canSeedDemoContent)
                                        <form method="POST" action="{{ route('demo-content.store') }}">
                                            @csrf
                                            <button class="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-800 dark:bg-zinc-900 dark:text-zinc-100" type="submit">Add Hello World examples</button>
                                        </form>
                                    @endif
                                </div>
                                @if ($canSeedDemoContent)
                                    <p class="mt-3 text-xs leading-5 text-zinc-500 dark:text-zinc-400">Adds a Markdown page with Mermaid and an isolated HTML artifact with interactive JavaScript to your personal workspace.</p>
                                @endif
                            </div>
                        @else
                            <section class="af-recent-pages">
                                <div class="af-section-title-row">
                                    <div>
                                        <p class="af-eyebrow">Discovery</p>
                                        <h2>Recently updated pages</h2>
                                    </div>
                                    <a href="{{ route('pages.index', ['workspace_uid' => $currentWorkspaceUid]) }}">View library →</a>
                                </div>
                                <div class="af-result-list mt-3 divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                                    @foreach ($pageHierarchyItems as $hierarchyItem)
                                        @php($page = $hierarchyItem->result->page)
                                        <a
                                            @class([
                                                'af-library-page block py-4',
                                                'af-library-page-child' => $hierarchyItem->depth > 0,
                                                'af-library-page-depth-'.$hierarchyItem->visualDepth => $hierarchyItem->depth > 0,
                                            ])
                                            data-page-hierarchy-depth="{{ $hierarchyItem->depth }}"
                                            href="{{ route('pages.show', $page) }}"
                                        >
                                            <div class="af-library-page-layout">
                                                @if ($hierarchyItem->parentTitle !== null)
                                                    <span class="af-library-page-branch" aria-hidden="true">↳</span>
                                                @endif
                                                <div class="min-w-0 flex-1">
                                                    @if ($hierarchyItem->parentTitle !== null)
                                                        <p class="af-library-page-parent">Under {{ $hierarchyItem->parentTitle }}</p>
                                                    @endif
                                                    <div class="flex items-center justify-between gap-4">
                                                        <h3 class="text-base font-semibold text-zinc-950 dark:text-zinc-50">{{ $page->title }}</h3>
                                                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $page->status->value }}</span>
                                                    </div>
                                                    @if ($page->description !== null)
                                                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $page->description }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    </section>

                    <section
                        id="workspace-members-panel"
                        data-workspace-panel="members"
                        role="tabpanel"
                        @if ($activeWorkspaceTab !== 'members') hidden @endif
                    >
                        <div class="af-section-title-row">
                            <div>
                                <p class="af-eyebrow">People</p>
                                <h2>Workspace members</h2>
                                <p>{{ $workspaceMemberPage->total }} people can access this workspace.</p>
                            </div>
                            @if ($canInviteToCurrentWorkspace)
                                <div class="flex flex-wrap gap-2">
                                    <button class="af-secondary-button" data-open-editor-dialog="workspace-add-collaborator-dialog" type="button">Add existing collaborator</button>
                                    <button class="af-primary-button" data-open-editor-dialog="workspace-invite-dialog" type="button">Invite teammate</button>
                                </div>
                            @endif
                        </div>

                        <div class="af-member-list">
                            @foreach ($workspaceMembers as $member)
                                <div class="af-member-row">
                                    <span class="af-member-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</span>
                                    <div class="af-member-identity">
                                        <strong>
                                            {{ $member->name }}
                                            @if ($member->isCurrentUser)
                                                <span>(you)</span>
                                            @endif
                                        </strong>
                                        <span>{{ $member->email }}</span>
                                    </div>

                                    @if ($canManageCurrentWorkspaceMembers && $currentWorkspaceUid !== null)
                                        <div class="af-member-actions">
                                            <form method="POST" action="{{ route('workspace-memberships.update', [$currentWorkspaceUid, $member->membershipUid]) }}">
                                                @csrf
                                                @method('PUT')
                                                <label>
                                                    <span class="sr-only">Role for {{ $member->name }}</span>
                                                    <select name="role">
                                                        @foreach ($workspaceMembershipRoles as $role)
                                                            <option value="{{ $role->value }}" @selected($member->role === $role)>{{ ucfirst($role->value) }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <button type="submit">Update role</button>
                                            </form>

                                            <form method="POST" action="{{ route('workspace-memberships.destroy', [$currentWorkspaceUid, $member->membershipUid]) }}">
                                                @csrf
                                                @method('DELETE')
                                                @if ($member->ownedPageCount > 0)
                                                    <label>
                                                        <span>Reassign owned pages to</span>
                                                        <select name="replacement_owner_user_uid" required>
                                                            <option value="">Select owner</option>
                                                            @foreach ($workspaceOwnershipCandidates as $replacementOwner)
                                                                @if ($replacementOwner->userUid !== $member->userUid)
                                                                    <option value="{{ $replacementOwner->userUid }}">{{ $replacementOwner->name }}</option>
                                                                @endif
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                @endif
                                                <button class="af-danger-link" type="submit">Remove member</button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="af-role-badge">{{ $member->role->value }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($workspaceMemberPage->lastPage > 1)
                            <nav class="af-pagination" aria-label="Workspace members pagination">
                                @if ($workspaceMemberPage->hasPreviousPage())
                                    <a href="{{ route('dashboard', ['tab' => 'members', 'members_page' => $workspaceMemberPage->currentPage - 1]) }}">← Previous</a>
                                @else
                                    <span></span>
                                @endif
                                <span>Page {{ $workspaceMemberPage->currentPage }} of {{ $workspaceMemberPage->lastPage }}</span>
                                @if ($workspaceMemberPage->hasNextPage())
                                    <a href="{{ route('dashboard', ['tab' => 'members', 'members_page' => $workspaceMemberPage->currentPage + 1]) }}">Next →</a>
                                @else
                                    <span></span>
                                @endif
                            </nav>
                        @endif

                        <section class="af-pending-invitations">
                            <div class="af-section-title-row">
                                <div>
                                    <p class="af-eyebrow">Invitations</p>
                                    <h2>Pending invitations</h2>
                                </div>
                            </div>
                            @if ($workspaceInvitations === [])
                                <p class="af-quiet-empty">No pending invitations.</p>
                            @else
                                <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    @foreach ($workspaceInvitations as $invitation)
                                        <div class="flex items-center justify-between gap-4 py-3 text-sm">
                                            <span class="text-zinc-800 dark:text-zinc-200">{{ $invitation->invitedEmail }}</span>
                                            <div class="flex items-center gap-3">
                                                <span class="af-role-badge">{{ $invitation->role->value }}</span>
                                                <form method="POST" action="{{ route('workspace-invitations.destroy', [$currentWorkspaceUid, $invitation->uid]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="af-danger-link" type="submit">Revoke</button>
                                                </form>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    </section>

                    <section
                        id="workspace-settings-panel"
                        data-workspace-panel="settings"
                        role="tabpanel"
                        @if ($activeWorkspaceTab !== 'settings') hidden @endif
                    >
                        <div class="af-settings-grid">
                            @if ($canManageCurrentWorkspaceSettings && $currentWorkspace !== null)
                                <section>
                                    <p class="af-eyebrow">Workspace settings</p>
                                    <h2>Collaboration defaults</h2>
                                    <form class="mt-5 space-y-4" method="POST" action="{{ route('workspaces.settings.update', $currentWorkspace) }}">
                                        @csrf
                                        @method('PUT')
                                        <label class="block">
                                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Workspace name</span>
                                            <input class="mt-1 w-full" name="name" type="text" maxlength="160" value="{{ $currentWorkspace->name }}" required>
                                        </label>
                                        <label class="af-setting-toggle">
                                            <input name="allow_editor_invites" type="hidden" value="0">
                                            <input name="allow_editor_invites" type="checkbox" value="1" @checked($currentWorkspace->allow_editor_invites)>
                                            <span><strong>Allow Editors to invite members</strong><small>Editors may invite Reader or Editor members, never Admins.</small></span>
                                        </label>
                                        <label class="af-setting-toggle">
                                            <input name="allow_editor_page_sharing" type="hidden" value="0">
                                            <input name="allow_editor_page_sharing" type="checkbox" value="1" @checked($currentWorkspace->allow_editor_page_sharing)>
                                            <span><strong>Allow Editors and page owners to share pages</strong><small>Admins and explicit page Admins always retain access management.</small></span>
                                        </label>
                                        <button class="af-primary-button" type="submit">Save workspace settings</button>
                                    </form>
                                </section>
                            @endif

                            <section>
                                <p class="af-eyebrow">Categories</p>
                                <h2>Workspace categories</h2>
                                @if ($categories === [])
                                    <p class="af-quiet-empty mt-4">No categories yet.</p>
                                @else
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($categories as $category)
                                            <span class="af-soft-chip">{{ $category->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($canCreateCategoriesInCurrentWorkspace && $currentWorkspaceUid !== null)
                                    <form class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto]" method="POST" action="{{ route('categories.store', $currentWorkspaceUid) }}">
                                        @csrf
                                        <label>
                                            <span class="sr-only">Category name</span>
                                            <input name="name" type="text" maxlength="120" placeholder="Architecture" required>
                                        </label>
                                        <button class="af-secondary-button" type="submit">Create category</button>
                                    </form>
                                @endif
                            </section>

                            <section>
                                <p class="af-eyebrow">New workspace</p>
                                <h2>Create shared workspace</h2>
                                <form class="mt-5 space-y-3" method="POST" action="{{ route('workspaces.store') }}">
                                    @csrf
                                    <label class="block">
                                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Workspace name</span>
                                        <input class="mt-2 w-full" name="name" type="text" placeholder="Shared workspace" required>
                                    </label>
                                    <button class="af-secondary-button" type="submit">Create workspace</button>
                                </form>
                            </section>
                        </div>
                    </section>
                </div>
            </section>
        </div>

        <dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="workspace-create-dialog" aria-labelledby="workspace-create-dialog-title">
            <div class="artifactflow-editor-dialog-panel">
                <div class="af-dialog-header">
                    <div>
                        <p class="af-eyebrow">New workspace</p>
                        <h2 id="workspace-create-dialog-title">Create shared workspace</h2>
                        <p>Start a shared space for pages, artifacts, members, and categories.</p>
                    </div>
                    <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close workspace form">Close</button>
                </div>
                <form class="grid gap-4 p-6" method="POST" action="{{ route('workspaces.store') }}">
                    @csrf
                    <label>
                        <span class="text-sm font-medium">Workspace name</span>
                        <input class="mt-2 w-full" name="name" type="text" maxlength="120" placeholder="Shared workspace" required>
                    </label>
                    <div class="flex justify-end">
                        <button class="af-primary-button" type="submit">Create workspace</button>
                    </div>
                </form>
            </div>
        </dialog>

        @if ($canCreateCategoriesInCurrentWorkspace && $currentWorkspaceUid !== null && $currentWorkspace !== null)
            <dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="category-create-dialog" aria-labelledby="category-create-dialog-title">
                <div class="artifactflow-editor-dialog-panel">
                    <div class="af-dialog-header">
                        <div>
                            <p class="af-eyebrow">Workspace category</p>
                            <h2 id="category-create-dialog-title">Create category for {{ $currentWorkspace->name }}</h2>
                            <p>Add a category to organize pages in the current workspace.</p>
                        </div>
                        <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close category form">Close</button>
                    </div>
                    <form class="grid gap-4 p-6" method="POST" action="{{ route('categories.store', $currentWorkspaceUid) }}">
                        @csrf
                        <label>
                            <span class="text-sm font-medium">Category name</span>
                            <input class="mt-2 w-full" name="name" type="text" maxlength="120" placeholder="Architecture" required>
                        </label>
                        <div class="flex justify-end">
                            <button class="af-primary-button" type="submit">Create category</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        @if ($canInviteToCurrentWorkspace)
            <x-workspace-invite-dialog
                dialog-id="workspace-invite-dialog"
                :workspace-uid="$currentWorkspaceUid"
                :roles="$workspaceInvitationRoles"
            />

            <dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="workspace-add-collaborator-dialog" aria-labelledby="workspace-add-collaborator-dialog-title">
                <div class="artifactflow-editor-dialog-panel">
                    <div class="af-dialog-header">
                        <div>
                            <p class="af-eyebrow">Workspace access</p>
                            <h2 id="workspace-add-collaborator-dialog-title">Add existing collaborator</h2>
                            <p>Add any registered coworker. They get access right away and an email letting them know &mdash; no invitation to accept.</p>
                        </div>
                        <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close add collaborator form">Close</button>
                    </div>
                    <form
                        class="grid gap-4 p-6"
                        method="POST"
                        action="{{ route('workspace-collaborators.store', $currentWorkspaceUid) }}"
                        data-known-user-picker
                        data-known-user-value-key="uid"
                        data-known-user-require-selection
                        data-search-url="{{ route('workspace-collaborators.search', $currentWorkspaceUid) }}"
                    >
                        @csrf
                        <input type="hidden" name="user_uid" value="" data-known-user-value required>
                        <div>
                            <label>
                                <span class="text-sm font-medium">Search people</span>
                                <input
                                    class="mt-2 w-full"
                                    type="search"
                                    autocomplete="off"
                                    placeholder="Search by name or email"
                                    data-known-user-search
                                    aria-controls="workspace-add-collaborator-results"
                                    aria-expanded="false"
                                    aria-autocomplete="list"
                                    role="combobox"
                                >
                            </label>
                            <ul id="workspace-add-collaborator-results" class="af-collaborator-results mt-2 hidden" role="listbox" data-known-user-results></ul>
                            <p class="af-collaborator-empty mt-2 hidden text-sm text-zinc-500 dark:text-zinc-400" data-known-user-empty>No matching registered coworkers.</p>
                        </div>
                        <p class="af-collaborator-selected hidden text-sm text-zinc-700 dark:text-zinc-300" data-known-user-selected>
                            Adding <span class="font-semibold text-zinc-950 dark:text-zinc-50" data-known-user-selected-label></span>.
                        </p>
                        <label>
                            <span class="text-sm font-medium">Workspace role</span>
                            <select class="mt-2 w-full" name="role" required>
                                @foreach ($workspaceInvitationRoles as $invitationRole)
                                    <option value="{{ $invitationRole->value }}">{{ ucfirst($invitationRole->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="flex justify-end">
                            <button class="af-primary-button" type="submit" data-known-user-submit disabled>Add to workspace</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    </div>
</x-layouts.app>
