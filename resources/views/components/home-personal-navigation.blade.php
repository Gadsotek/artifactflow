@props(['recentPages', 'favoritePages'])
<div class="af-home-personal">
    <section class="af-home-collection" aria-labelledby="home-recent-title">
        <div class="af-section-heading">
            <h2 id="home-recent-title">Continue where you left off</h2>
            <a href="{{ route('navigation.recent') }}" aria-label="View all recently opened pages">View all →</a>
        </div>
        @if ($recentPages === [])
            <div class="af-collection-empty">
                <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>
                <p>Pages you open will appear here.<a href="{{ route('pages.index') }}">Explore your Library →</a></p>
            </div>
        @else
            <x-navigation-page-list :pages="$recentPages" />
        @endif
    </section>
    <section class="af-home-collection" aria-labelledby="home-favorites-title">
        <div class="af-section-heading">
            <h2 id="home-favorites-title">Favorites</h2>
            <a href="{{ route('navigation.favorites') }}" aria-label="View all favorite pages">View all →</a>
        </div>
        @if ($favoritePages === [])
            <div class="af-collection-empty">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m12 3 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z"/></svg>
                <p>Keep useful pages close.<span>Use Favorite on any page you can open.</span></p>
            </div>
        @else
            <x-navigation-page-list :pages="$favoritePages" />
        @endif
    </section>
</div>
