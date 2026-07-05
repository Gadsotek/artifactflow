<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-activity-dialog" aria-labelledby="page-activity-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Audit trail</p>
                <h2 id="page-activity-dialog-title">Page activity</h2>
                <p>Important page changes recorded without exposing private content or access-target metadata.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close page activity">Close</button>
        </div>
        <div class="af-dialog-scroll">
            <div class="af-activity-list">
                @foreach ($pageActivity as $activity)
                    <article>
                        <span></span>
                        <div>
                            <h3>{{ $activity->summary }}</h3>
                            <p>{{ $activity->actorName }} · {{ $activity->occurredAt->toDateTimeString() }} UTC</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </div>
</dialog>
