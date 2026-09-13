@props(['pages'])
<div class="af-navigation-pages">
    @foreach ($pages as $item)
        <a class="af-navigation-page" href="{{ $item->url }}">
            <span class="af-navigation-type" aria-hidden="true">{{ $item->type === 'Markdown' ? 'M↓' : ($item->type === 'HTML artifact' ? '‹/›' : '▤') }}</span>
            <span class="min-w-0 flex-1">
                <strong class="block break-words">{{ $item->title }}</strong>
                <span class="af-navigation-meta">{{ $item->type }}@if ($item->workspace !== null) · {{ $item->workspace }}@endif</span>
            </span>
            @if ($item->status === 'archived')<span class="af-status-badge">Archived</span>@endif
            @if ($item->favorite)<span class="af-favorite-star" aria-label="Favorite">★</span>@endif
            <span aria-hidden="true">↗</span>
        </a>
    @endforeach
</div>
