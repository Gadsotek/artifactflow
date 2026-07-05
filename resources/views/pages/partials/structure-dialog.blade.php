<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-structure-dialog" aria-labelledby="page-structure-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Navigation</p>
                <h2 id="page-structure-dialog-title">Page hierarchy</h2>
                <p>Only parent and child pages this viewer is authorized to see are shown.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close page hierarchy">Close</button>
        </div>
        <div class="af-dialog-scroll af-structure-grid">
            <section>
                <h3>Parent</h3>
                @if ($pageHierarchy->parent !== null)
                    <a class="af-structure-link" href="{{ route('pages.show', $pageHierarchy->parent->pageUid) }}" aria-label="Parent page: {{ $pageHierarchy->parent->title }}">
                        <strong>{{ $pageHierarchy->parent->title }}</strong>
                        <span>{{ $pageHierarchy->parent->status->value }}</span>
                    </a>
                @else
                    <p class="af-quiet-empty">No visible parent page.</p>
                @endif
            </section>
            <section>
                <h3>Children</h3>
                <div class="space-y-2">
                    @forelse ($pageHierarchy->children as $childPage)
                        <a class="af-structure-link" href="{{ route('pages.show', $childPage->pageUid) }}" aria-label="Child page: {{ $childPage->title }}">
                            <strong>{{ $childPage->title }}</strong>
                            <span>{{ $childPage->status->value }}</span>
                        </a>
                    @empty
                        <p class="af-quiet-empty">No visible child pages.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</dialog>
