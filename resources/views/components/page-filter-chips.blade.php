@if ($chips !== [])
    <nav class="af-filter-chips" aria-label="Active filters">
        @foreach ($chips as $chip)
            <a href="{{ $chip->url }}" data-remove-filter="{{ $chip->key }}" aria-label="Remove filter: {{ $chip->label }}">
                <span>{{ $chip->label }}</span><span aria-hidden="true">×</span>
            </a>
        @endforeach
    </nav>
@endif
