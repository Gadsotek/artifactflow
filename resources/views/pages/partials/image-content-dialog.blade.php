<dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="image-version-dialog" aria-labelledby="image-version-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">New immutable version</p>
                <h2 id="image-version-dialog-title">Replace image</h2>
                <p>The replacement is decoded, stripped of metadata and non-pixel payloads, and re-encoded before storage.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close image replacement">Close</button>
        </div>

        <form class="grid gap-4 p-6" method="POST" action="{{ route('pages.versions.store', $page) }}" enctype="multipart/form-data">
            @csrf
            <input name="mode" type="hidden" value="upload">
            <input name="base_version_uid" type="hidden" value="{{ $baseVersionUid }}">
            @include('pages.partials.change-summary-field')
            <label class="block">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">PNG or JPEG image</span>
                <input class="mt-3 block w-full text-sm" name="image_file" type="file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" required>
            </label>
            <div class="flex justify-end">
                <button class="af-primary-button" type="submit">Replace image</button>
            </div>
        </form>
    </div>
</dialog>
