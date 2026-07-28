<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-provenance-dialog" aria-labelledby="page-provenance-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Authoritative lineage</p>
                <h2 id="page-provenance-dialog-title">Provenance</h2>
                <p>Declared producer metadata and ArtifactFlow-observed ingest facts for the current version.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close provenance">Close</button>
        </div>
        <div class="af-dialog-scroll">
            @include('pages.partials.provenance-summary', ['provenance' => $pageProvenance])
        </div>
    </div>
</dialog>
