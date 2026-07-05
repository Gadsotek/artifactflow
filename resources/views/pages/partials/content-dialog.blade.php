@use('App\Domain\PageCatalog\PageType')
@php($contentDialogId = $page->type === PageType::HtmlArtifact ? 'html-source-editor' : 'page-content-dialog')
<dialog class="artifactflow-editor-dialog" data-editor-dialog id="{{ $contentDialogId }}" aria-labelledby="{{ $contentDialogId }}-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">New immutable version</p>
                <h2 id="{{ $contentDialogId }}-title">{{ $page->type === PageType::Markdown ? 'Edit Markdown' : 'Edit HTML source' }}</h2>
                <p>
                    {{ $page->type === PageType::Markdown
                        ? 'Format the page directly or switch to portable Markdown source.'
                        : 'Edit untrusted source safely. HTML never executes inside this editor.' }}
                </p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close content editor">Close</button>
        </div>

        <div class="af-dialog-scroll">
            @if ($pagePresenceEnabled ?? false)
                <div
                    class="mb-4 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500 dark:bg-amber-950 dark:text-amber-100"
                    data-page-presence-editing-warning
                    hidden
                    role="status"
                    aria-live="polite"
                >
                    <span data-page-presence-editing-warning-status></span>
                </div>
            @endif
            <form
                class="space-y-4"
                method="POST"
                action="{{ route('pages.versions.store', $page) }}"
                data-content-editor
                data-editor-capabilities="{{ $page->type === PageType::Markdown ? 'rich-markdown' : 'line-numbers syntax-highlighting' }}"
                data-editor-language="{{ $page->type === PageType::Markdown ? 'markdown' : 'html' }}"
                data-editor-layout="{{ $page->type === PageType::Markdown ? 'rich' : 'source' }}"
            >
                @csrf
                <input name="mode" type="hidden" value="editor">
                <input name="base_version_uid" type="hidden" value="{{ $baseVersionUid }}">
                <div hidden class="border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950 dark:bg-red-950 dark:text-red-100" data-concurrency-error aria-live="polite"></div>

                @if ($page->type === PageType::Markdown)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="artifactflow-editor-view-switch" data-editor-view-switch role="tablist" aria-label="Editor view">
                            <button data-active="true" data-editor-view-button data-editor-view="rich" role="tab" type="button">Rich editor</button>
                            <button data-editor-view-button data-editor-view="source" role="tab" type="button">Markdown source</button>
                        </div>
                        <span class="text-xs text-zinc-500" data-editor-language-label>Rich Markdown</span>
                    </div>
                    @include('pages.partials.markdown-toolbar')
                    <div
                        class="artifactflow-markdown artifactflow-rich-editor"
                        contenteditable="true"
                        data-rich-markdown-editor
                        aria-label="Page content"
                    >{!! $renderedEditorMarkdown !!}</div>
                @endif

                <div class="min-w-0">
                    <label class="sr-only" for="page-content-editor">Page source</label>
                    <div class="artifactflow-editor-shell" data-source-editor-mount></div>
                    <textarea
                        class="min-h-[32rem] w-full rounded-md border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50"
                        id="page-content-editor"
                        name="content"
                        data-editor-textarea
                        required
                    >{{ old('content', $sourcePreview) }}</textarea>
                    <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
                        <span data-editor-status>{{ $page->type === PageType::Markdown ? 'Rich Markdown editor ready' : 'Source editor ready' }}</span>
                        <span data-editor-count></span>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4">
                        <span class="text-xs font-medium uppercase tracking-wide text-zinc-500">Cmd/Ctrl + S to save</span>
                        <button class="af-primary-button" type="submit">Save new version</button>
                    </div>
                </div>
            </form>

            @if ($page->type === PageType::HtmlArtifact)
                <form class="mt-6 border-t border-zinc-200 pt-5 dark:border-zinc-800" method="POST" action="{{ route('pages.versions.store', $page) }}" enctype="multipart/form-data">
                    @csrf
                    <input name="mode" type="hidden" value="upload">
                    <input name="base_version_uid" type="hidden" value="{{ $baseVersionUid }}">
                    <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                        <label class="block">
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Replace from HTML file</span>
                            <span class="mt-1 block text-xs text-zinc-500">Upload one self-contained <code>.html</code> file to create a new version.</span>
                            <input class="mt-3 block w-full text-sm" name="html_file" type="file" accept=".html,text/html" required>
                        </label>
                        <button class="af-secondary-button" type="submit">Upload replacement</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</dialog>
