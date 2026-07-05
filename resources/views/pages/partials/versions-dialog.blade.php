<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-versions-dialog" aria-labelledby="page-versions-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Immutable history</p>
                <h2 id="page-versions-dialog-title">Version history</h2>
                <p>Inspect hashes, scan status, authorship, and restore an older version as a new current version.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close version history">Close</button>
        </div>
        <div class="af-dialog-scroll">
            <div class="af-version-list">
                @foreach ($versions as $historyVersion)
                    <article>
                        <div class="flex items-center justify-between gap-3">
                            <h3>Version {{ $historyVersion->version_number }}</h3>
                            @if ($historyVersion->uid === $page->current_version_uid)
                                <span class="af-role-badge">Current</span>
                            @endif
                        </div>
                        <p>Changed by {{ $historyVersion->creator->name }} · {{ $historyVersion->created_at->toDateString() }} · {{ $historyVersion->scan_status->value }} · {{ $historyVersion->byte_size }} bytes</p>
                        <code>SHA-256 {{ $historyVersion->content_hash }}</code>
                        <div class="mt-3 flex flex-wrap justify-end gap-2">
                            <a class="af-secondary-button" href="{{ route('pages.versions.show', [$page, $historyVersion]) }}">Inspect</a>
                            @if ($canMutateContent && $historyVersion->uid !== $page->current_version_uid)
                                <form method="POST" action="{{ route('pages.versions.restore', [$page, $historyVersion]) }}">
                                    @csrf
                                    {{-- Optimistic-concurrency token: the version current when this dialog
                                         was rendered. The server refuses the restore with a 409 if the page
                                         has since moved on, instead of silently overwriting the newer save. --}}
                                    <input type="hidden" name="current_version_uid" value="{{ $page->current_version_uid }}">
                                    <button class="af-secondary-button" type="submit">Restore</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </div>
</dialog>
