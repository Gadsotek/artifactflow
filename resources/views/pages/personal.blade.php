<x-layouts.app :title="$title">
    <div class="af-app-surface min-h-screen">
        <header class="af-page-header">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-6 py-6">
                <div>
                    <p class="af-eyebrow">Your library</p>
                    <h1>{{ $title }}</h1>
                    <p class="af-page-intro">{{ $collection === 'recent' ? 'Pick up where you left off. Only you can see this history.' : 'Keep useful pages close. Your favorites are private.' }}</p>
                </div>
                @if ($collection === 'recent' && $pages !== [])
                    <form method="POST" action="{{ route('navigation.recent.clear') }}">
                        @csrf
                        @method('DELETE')
                        <button class="af-secondary-button" type="submit">Clear history</button>
                    </form>
                @endif
            </div>
        </header>
        <section class="mx-auto max-w-7xl px-6 py-8" aria-label="{{ $title }} pages">
            @if (session('status'))
                <p class="af-callout mb-6" role="status">{{ session('status') }}</p>
            @endif
            @if ($pages === [])
                <div class="af-empty-state">
                    <h2>{{ $collection === 'recent' ? 'No recently opened pages' : 'No favorites yet' }}</h2>
                    <p>{{ $collection === 'recent' ? 'Pages you open will appear here so you can get back to them quickly.' : 'Use Favorite on any page to add it to this list.' }}</p>
                    <a class="af-primary-button" href="{{ route('pages.index') }}">Browse Library</a>
                </div>
            @else
                <x-navigation-page-list :pages="$pages" />
            @endif
        </section>
    </div>
</x-layouts.app>
