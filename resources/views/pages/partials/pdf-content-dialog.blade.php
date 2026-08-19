<dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="pdf-version-dialog" aria-labelledby="pdf-version-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">New immutable version</p>
                <h2 id="pdf-version-dialog-title">Replace PDF</h2>
                <p>The original is validated, bounded native text is extracted and scanned, and the previous version remains in history.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close PDF replacement">Close</button>
        </div>

        <form class="grid gap-4 p-6" method="POST" action="{{ route('pages.versions.store', $page) }}" enctype="multipart/form-data">
            @csrf
            <input name="mode" type="hidden" value="upload">
            <input name="base_version_uid" type="hidden" value="{{ $baseVersionUid }}">
            @include('pages.partials.change-summary-field')
            <label class="block">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">PDF document</span>
                <input class="mt-3 block w-full text-sm" name="pdf_file" type="file" accept=".pdf,application/pdf" required>
            </label>
            <div class="flex justify-end">
                <button class="af-primary-button" type="submit">Replace PDF</button>
            </div>
        </form>
    </div>
</dialog>
