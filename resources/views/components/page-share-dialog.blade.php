@props(['page', 'canManageAccess'])
<dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="page-share-dialog" aria-labelledby="page-share-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div><p class="af-eyebrow">Share</p><h2 id="page-share-title">Share page</h2><p>Choose who should be able to open this page.</p></div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close sharing">Close</button>
        </div>
        <div class="af-share-choices">
            <section data-copy-page-link-control>
                <h3>Internal link</h3><p>For people who already have access. Sign-in is required.</p>
                <button class="af-secondary-button" type="button" data-copy-page-link data-copy-page-link-url="{{ route('pages.show', $page) }}">Copy internal link</button>
                <span data-copy-page-link-status role="status"></span>
            </section>
            <section>
                <h3>Page access</h3><p>Review the people and workspace grants that control access.</p>
                <button class="af-secondary-button" type="button" data-open-editor-dialog="page-access-dialog">{{ $canManageAccess ? 'Manage page access' : 'View page access' }}</button>
            </section>
            @if ($canManageAccess)
                <section>
                    <h3>External link</h3><p>Create or manage an expiring or one-time link. Anyone holding it may be able to view the shared content.</p>
                    <button class="af-secondary-button" type="button" data-open-editor-dialog="page-external-share-dialog">External link options</button>
                </section>
            @endif
        </div>
    </div>
</dialog>
