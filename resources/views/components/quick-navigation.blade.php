@props(['workspaces'])
<dialog class="af-quick-dialog" data-quick-navigation data-search-url="{{ route('navigation.search') }}" aria-labelledby="quick-navigation-title">
    <div class="af-quick-heading">
        <h2 id="quick-navigation-title">Search or jump to</h2>
        <button class="af-secondary-button" type="button" data-close-quick-navigation aria-label="Close search">Esc</button>
    </div>
    <label class="af-quick-search">
        <span class="af-quick-search-label">Find a page</span>
        <span class="af-search-field">
            <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
            <input class="af-search-input" type="search" maxlength="200" placeholder="Find a page by title…" autocomplete="off" data-quick-query autofocus>
        </span>
    </label>
    <label class="af-quick-scope">
        <span>Search in</span>
        <select data-quick-workspace>
            <option value="all">All accessible workspaces</option>
            @foreach ($workspaces as $workspace)
                <option value="{{ $workspace->uid }}">{{ str_repeat('— ', $workspace->depth) }}{{ $workspace->name }}</option>
            @endforeach
        </select>
    </label>
    <p class="af-quick-status" data-quick-status role="status" aria-live="polite">Your recent pages and favorites</p>
    <div class="af-quick-results" data-quick-results></div>
    <footer class="af-quick-footer">
        <span>↑ ↓ to move · Enter to open</span>
        <a href="{{ route('pages.index') }}" data-quick-library>Search content in Library →</a>
    </footer>
</dialog>
