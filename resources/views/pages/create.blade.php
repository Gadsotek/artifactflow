@use('App\Domain\PageCatalog\PageCreationMode')
@use('App\Domain\PageCatalog\PageStatus')
@use('App\Domain\PageCatalog\PageType')
<x-layouts.app title="Create page">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div>
                    <p class="af-eyebrow">Compose</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Create page</h1>
                    <p class="af-page-intro">Write portable knowledge, paste an artifact, or upload a complete HTML file.</p>
                </div>
                <a class="af-secondary-button" href="{{ route('pages.index') }}">Cancel</a>
            </div>
        </header>

        <main class="af-editor-page mx-auto max-w-5xl px-6 py-8">
            @if ($errors->any())
                <div class="mb-6 border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950 dark:bg-red-950 dark:text-red-100">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form
                class="af-editor-form space-y-8"
                method="POST"
                action="{{ route('pages.store') }}"
                enctype="multipart/form-data"
                data-create-page-form
                data-content-editor
                data-editor-capabilities="rich-markdown source-code"
                data-editor-language="markdown"
                data-editor-language-select="type"
                data-editor-layout="rich"
                data-html-draft-preview-form
                data-html-draft-preview-endpoint="{{ $draftPreviewUrl }}"
                data-create-page-category
            >
                @csrf

                <section class="af-create-source-grid" data-create-page-essential-fields>
                    <div class="af-create-section-heading">
                        <span>01</span>
                        <div>
                            <h2>Choose the source</h2>
                            <p>The form adapts to the way this page will be created.</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Workspace</span>
                            <select class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" data-create-page-workspace-select name="workspace_uid" required>
                                @foreach ($editableWorkspaces as $workspace)
                                    <option value="{{ $workspace->uid }}" @selected(old('workspace_uid', $selectedWorkspaceUid) === $workspace->uid)>{{ $workspace->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Page type</span>
                            <select class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" name="type" required>
                                <option value="{{ PageType::Markdown->value }}" @selected(old('type', PageType::Markdown->value) === PageType::Markdown->value)>Markdown page</option>
                                <option value="{{ PageType::HtmlArtifact->value }}" @selected(old('type') === PageType::HtmlArtifact->value)>HTML artifact</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Content source</span>
                            <select class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" name="mode">
                                <option value="{{ PageCreationMode::Markdown->value }}" @selected(old('mode', PageCreationMode::Markdown->value) === PageCreationMode::Markdown->value)>Write Markdown</option>
                                <option value="{{ PageCreationMode::HtmlPaste->value }}" @selected(old('mode') === PageCreationMode::HtmlPaste->value)>Paste HTML</option>
                                <option value="{{ PageCreationMode::HtmlUpload->value }}" @selected(old('mode') === PageCreationMode::HtmlUpload->value)>Upload HTML file</option>
                            </select>
                        </label>
                    </div>

                    <label class="mt-5 block">
                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Title</span>
                        <input class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" name="title" type="text" value="{{ old('title') }}" required>
                        <span class="mt-1 block text-xs text-zinc-500">For uploads, the filename proposes a title that you can edit.</span>
                    </label>
                </section>

                <section class="space-y-5" data-create-page-optional-fields>
                    <div class="af-create-section-heading">
                        <span>02</span>
                        <div>
                            <h2>Organize the page</h2>
                            <p>Add context for discovery. These details remain editable, and apply to uploaded artifacts too.</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Status</span>
                            <select class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" name="status" required>
                                <option value="{{ PageStatus::Draft->value }}" @selected(old('status', PageStatus::Draft->value) === PageStatus::Draft->value)>Draft</option>
                                <option value="{{ PageStatus::Approved->value }}" @selected(old('status') === PageStatus::Approved->value)>Approved</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Tags</span>
                            <input class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" name="tags" type="text" value="{{ old('tags') }}" placeholder="architecture, runbook">
                        </label>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div class="block">
                            <div class="flex items-center gap-2">
                                <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200" for="page-category">Category</label>
                                <button
                                    class="af-icon-button af-inline-field-action"
                                    data-open-editor-dialog="page-category-create-dialog"
                                    type="button"
                                    aria-label="Create category"
                                    title="Create category"
                                >
                                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                                </button>
                            </div>
                            <select class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" data-create-page-category-select id="page-category" name="category_uid">
                                <option value="">None</option>
                                @foreach ($categories as $category)
                                    <option
                                        data-create-page-category-workspace-uid="{{ $category->workspace_uid }}"
                                        value="{{ $category->uid }}"
                                        @selected(old('category_uid') === $category->uid)
                                    >{{ $category->name }}</option>
                                @endforeach
                                @if (trim((string) old('category_name', '')) !== '')
                                    <option
                                        data-create-page-category-option
                                        data-create-page-category-workspace-uid="{{ old('workspace_uid', $selectedWorkspaceUid) }}"
                                        value=""
                                        selected
                                    >{{ old('category_name') }} (new)</option>
                                @endif
                            </select>
                            <input data-create-page-category-name name="category_name" type="hidden" value="{{ old('category_name') }}">
                            <span class="mt-2 block text-xs text-zinc-500">Categories are scoped to the selected workspace.</span>
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Parent page</span>
                            <select class="mt-2 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" data-create-page-parent-select name="parent_page_uid">
                                <option value="">None</option>
                                @foreach ($parentPages as $parentPage)
                                    <option
                                        data-create-page-parent-workspace-uid="{{ $parentPage->workspace_uid }}"
                                        value="{{ $parentPage->uid }}"
                                        @selected($selectedParentPageUid === $parentPage->uid)
                                    >{{ $parentPage->title }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Description</span>
                        <textarea class="mt-2 min-h-24 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950" name="description">{{ old('description') }}</textarea>
                    </label>
                </section>

                <section class="space-y-5" data-create-page-upload-fields hidden>
                    <div class="af-create-section-heading">
                        <span>03</span>
                        <div>
                            <h2>Upload artifact</h2>
                            <p>The HTML file supplies the page content.</p>
                        </div>
                    </div>

                    <label class="af-file-drop block">
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Single-file HTML artifact</span>
                        <span class="mt-1 block text-xs text-zinc-500">Choose one self-contained <code>.html</code> file. It remains untrusted and will run only inside the isolated artifact sandbox.</span>
                        <input class="mt-4 block w-full text-sm text-zinc-700 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-900 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white dark:text-zinc-300 dark:file:bg-zinc-100 dark:file:text-zinc-950" name="html_file" type="file" accept=".html,text/html">
                    </label>
                    @include('pages.partials.artifact-self-contained-hint')
                </section>

                <section class="space-y-5" data-create-page-content-fields>
                    <div class="af-create-section-heading">
                        <span>03</span>
                        <div>
                            <h2>Add content</h2>
                            <p>Use the rich editor for Markdown or switch to HTML source when pasting an artifact.</p>
                        </div>
                    </div>

                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200" for="create-page-content-editor">Content</label>
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
                        <div class="artifactflow-editor-shell" data-source-editor-mount></div>
                        <textarea
                            class="mt-2 min-h-72 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-950"
                            id="create-page-content-editor"
                            name="content"
                            data-editor-textarea
                        >{{ old('content') }}</textarea>
                        <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
                            <span data-editor-status>Rich Markdown editor ready</span>
                            <span data-editor-count></span>
                        </div>
                    </div>
                </section>

                <section class="space-y-3 rounded-md border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900" data-html-draft-preview hidden>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-zinc-950 dark:text-zinc-50">HTML draft preview</h2>
                            <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">Runs only in an opaque sandbox with scripts allowed and network, forms, same-origin access, and top navigation blocked.</p>
                        </div>
                        <button class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-800 disabled:cursor-wait disabled:opacity-60" data-html-draft-preview-button type="button">Preview HTML before saving</button>
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400" data-html-draft-preview-status aria-live="polite"></p>
                    @include('pages.partials.artifact-self-contained-hint')
                    @include('pages.partials.artifact-sensitive-data-warning')
                    <iframe class="h-[32rem] w-full rounded-md border border-zinc-300 bg-white dark:border-zinc-700" data-html-draft-preview-frame name="artifactflow-html-draft-preview" sandbox="allow-scripts" allow="" referrerpolicy="no-referrer" title="Unsaved HTML draft preview"></iframe>
                </section>

                <div class="af-create-submit">
                    <button class="af-primary-button" type="submit">Save page</button>
                </div>
            </form>
        </main>

        <dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="page-category-create-dialog" aria-labelledby="page-category-create-dialog-title">
            <div class="artifactflow-editor-dialog-panel">
                <div class="af-dialog-header">
                    <div>
                        <p class="af-eyebrow">Page organization</p>
                        <h2 id="page-category-create-dialog-title">Create a new category</h2>
                        <p>Add it to <span class="font-semibold" data-create-page-category-workspace-name></span> and select it for this page.</p>
                    </div>
                    <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close category form">Close</button>
                </div>
                <form class="grid gap-4 p-6" data-create-page-category-form>
                    <label>
                        <span class="text-sm font-medium">Category name</span>
                        <input class="mt-2 w-full" data-create-page-category-input name="category_draft_name" type="text" maxlength="120" value="{{ old('category_name') }}" placeholder="Architecture" required>
                    </label>
                    <p class="text-xs leading-5 text-zinc-500 dark:text-zinc-400">The category will be created atomically when you save the page.</p>
                    <div class="flex justify-end">
                        <button class="af-primary-button" type="submit">Use category</button>
                    </div>
                </form>
            </div>
        </dialog>
    </div>
</x-layouts.app>
