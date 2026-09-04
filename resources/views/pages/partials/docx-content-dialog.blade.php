<dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="docx-version-dialog" aria-labelledby="docx-version-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">New immutable version</p>
                <h2 id="docx-version-dialog-title">Replace Word document</h2>
                <p>The replacement is validated and converted again. The previous original and PDF preview remain in history.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close Word document replacement">Close</button>
        </div>

        <form class="grid gap-4 p-6" method="POST" action="{{ route('pages.versions.store', $page) }}" enctype="multipart/form-data">
            @csrf
            <input name="mode" type="hidden" value="upload">
            <input name="base_version_uid" type="hidden" value="{{ $baseVersionUid }}">
            @include('pages.partials.change-summary-field')
            <label class="block">
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">DOCX document</span>
                <input class="mt-3 block w-full text-sm" name="docx_file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                @error('docx_file')
                    <span class="mt-3 block text-sm font-medium text-red-700 dark:text-red-300" role="alert">{{ $message }} Select the document again after correcting the issue.</span>
                @enderror
            </label>
            <div class="flex justify-end">
                <button class="af-primary-button" type="submit">Replace Word document</button>
            </div>
        </form>
    </div>
</dialog>
