@props(['workspaces', 'currentWorkspaceUid', 'createDialog', 'dashboard' => false])
<aside class="af-context-panel af-workspace-navigation" data-workspace-navigation>
    <div class="af-section-heading">
        <h2>Workspaces</h2>
        <button class="af-icon-button" data-open-editor-dialog="{{ $createDialog }}" type="button" aria-label="Create workspace" title="Create workspace">+</button>
    </div>
    <div class="af-workspace-search">
        <label for="workspace-navigation-search">Find a workspace</label>
        <div class="af-search-field">
            <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
            <input id="workspace-navigation-search" class="af-search-input" type="search" placeholder="Filter workspaces…" data-workspace-search autocomplete="off">
        </div>
    </div>
    <ul class="af-workspace-list" aria-label="Workspace selection">
        @if (!$dashboard)
            <li><a class="af-workspace-link {{ $currentWorkspaceUid === 'all' ? 'af-option-active' : '' }}" @if ($currentWorkspaceUid === 'all') aria-current="true" @endif href="{{ route('pages.index', ['workspace_uid' => 'all']) }}">
                <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span>All accessible workspaces</span>
            </a></li>
        @endif
        @foreach ($workspaces as $workspace)
            <li data-workspace-option data-workspace-label="{{ $workspace->name }}">
                @if ($dashboard)
                    <form method="POST" action="{{ route('workspaces.switch', $workspace->uid) }}">
                        @csrf
                        <button class="af-workspace-link {{ $workspace->uid === $currentWorkspaceUid ? 'af-option-active' : '' }}" type="submit" @if ($workspace->uid === $currentWorkspaceUid) aria-current="true" @endif data-workspace-depth="{{ $workspace->depth }}">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 7V5a1 1 0 0 1 1-1h5l2 3h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/></svg>
                            <span class="af-workspace-name" data-workspace-name>{{ $workspace->name }}</span>
                            <span class="af-workspace-role" title="{{ ucfirst($workspace->role->value) }}">{{ ucfirst($workspace->role->value) }}</span>
                        </button>
                    </form>
                @else
                    <a class="af-workspace-link {{ $workspace->uid === $currentWorkspaceUid ? 'af-option-active' : '' }}" @if ($workspace->uid === $currentWorkspaceUid) aria-current="true" @endif href="{{ route('pages.index', ['workspace_uid' => $workspace->uid]) }}" data-workspace-depth="{{ $workspace->depth }}">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 7V5a1 1 0 0 1 1-1h5l2 3h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Z"/></svg>
                        <span class="af-workspace-name" data-workspace-name>{{ $workspace->name }}</span>
                        @if (!$workspace->isMembership && $workspace->accessLabel !== null)<span class="af-workspace-role">{{ $workspace->accessLabel }}</span>@endif
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
    <p class="af-empty-note" data-workspace-empty hidden>No matching workspaces.</p>
</aside>
