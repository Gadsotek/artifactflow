@use('App\Domain\PageCatalog\PageSecurityScanStatus')
@use('App\Domain\PageCatalog\PageStatus')
@use('App\Domain\PageCatalog\PageType')
<x-layouts.app title="{{ $page->title }}">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-[100rem] items-center justify-between px-6 py-4">
                <div>
                    <p class="af-eyebrow">{{ $workspace?->name }}</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $page->title }}</h1>
                    <p class="af-page-intro">{{ ucfirst(str_replace('_', ' ', $page->type->value)) }} · {{ ucfirst($page->status->value) }}</p>
                </div>
                <div class="af-page-actions flex items-center gap-3">
                    @if ($pagePresenceEnabled)
                        <div
                            class="af-page-presence"
                            data-page-presence
                            data-page-presence-page-uid="{{ $page->uid }}"
                            data-page-presence-endpoint="{{ route('pages.presence.update', $page, false) }}"
                            data-page-presence-current-user-uid="{{ $pagePresenceActorUid }}"
                            data-page-presence-current-user-name="{{ $pagePresenceActorName }}"
                            hidden
                        >
                            <span data-page-presence-status role="status" aria-live="polite"></span>
                        </div>
                    @endif
                    <div class="flex items-center gap-2" data-copy-page-link-control>
                        <button
                            class="af-secondary-button"
                            data-copy-page-link
                            data-copy-page-link-url="{{ route('pages.show', $page) }}"
                            type="button"
                        >
                            Copy page link
                        </button>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400" data-copy-page-link-status aria-live="polite"></span>
                    </div>
                    <a class="af-secondary-button" href="{{ route('pages.index') }}">Library</a>
                    <a class="af-primary-button" href="{{ route('pages.create', ['workspace_uid' => $page->workspace_uid, 'parent_page_uid' => $page->uid]) }}">Create page</a>
                </div>
            </div>
        </header>

        <main class="af-document-layout af-document-layout-wide mx-auto max-w-[100rem] px-6 py-8">
            @if ($pagePresenceEnabled && $page->current_version_uid !== null)
                <div
                    class="af-callout mb-6 flex flex-wrap items-center justify-between gap-3"
                    data-page-version-notice
                    data-current-version-uid="{{ $page->current_version_uid }}"
                    hidden
                    role="status"
                    aria-live="polite"
                >
                    <span>A newer version is available.</span>
                    <a class="af-secondary-button" href="{{ route('pages.show', $page) }}">View newer version</a>
                </div>
            @endif

            @if (session('status'))
                <div class="af-callout mb-6">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950 dark:bg-red-950 dark:text-red-100">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="af-page-tools" data-page-tools aria-label="Page tools">
                @if ($canMutateContent && $sourcePreview !== null)
                    <button
                        data-open-editor-dialog="{{ $page->type === PageType::HtmlArtifact ? 'html-source-editor' : 'page-content-dialog' }}"
                        type="button"
                        title="{{ $page->type === PageType::HtmlArtifact ? 'Edit HTML source' : 'Edit Markdown' }}"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m4 20 4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20Zm9.5-12.5 3 3"/></svg>
                        <span>{{ $page->type === PageType::HtmlArtifact ? 'Edit HTML source' : 'Edit Markdown' }}</span>
                    </button>
                @endif
                <button data-open-editor-dialog="page-metadata-dialog" type="button" title="Metadata">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 5h16M4 12h16M4 19h16M8 3v4m8 3v4M10 17v4"/></svg>
                    <span>Metadata</span>
                </button>
                @if ($canMoveWorkspace)
                    <button data-open-editor-dialog="page-workspace-move-dialog" type="button" title="Move workspace">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h9m0 0-3-3m3 3-3 3m10 7h-9m0 0 3-3m-3 3 3 3M5 17V9m14 6V7"/></svg>
                        <span>Move workspace</span>
                    </button>
                @endif
                <button data-open-editor-dialog="page-structure-dialog" type="button" title="Page hierarchy">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v5m0 0H6v4m6-4h6v4M4 14h4v4H4v-4Zm8 0h4v4h-4v-4Zm8 0v4h-4v-4h4Z"/></svg>
                    <span>Structure</span>
                </button>
                <button data-open-editor-dialog="page-versions-dialog" type="button" title="Version history">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8.5M4 4v4.5h4.5M12 7v5l3 2"/></svg>
                    <span>Versions</span>
                </button>
                <button data-open-editor-dialog="page-activity-dialog" type="button" title="Page activity">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 18V9m5 9V5m6 13v-7m5 7V3"/></svg>
                    <span>Activity</span>
                </button>
                <button data-open-editor-dialog="page-access-dialog" type="button" title="Access overrides">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3 4 7v5c0 5 3.4 8.7 8 10 4.6-1.3 8-5 8-10V7l-8-4Zm0 5v8m-4-4h8"/></svg>
                    <span>Access</span>
                </button>
                @if ($canEdit || $canArchive || $canDelete)
                    <button data-open-editor-dialog="page-lifecycle-dialog" type="button" title="Lifecycle">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3v4m0 10v4M3 12h4m10 0h4M5.6 5.6l2.8 2.8m7.2 7.2 2.8 2.8m0-12.8-2.8 2.8m-7.2 7.2-2.8 2.8"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>Lifecycle</span>
                    </button>
                @endif
            </div>

            <article class="af-document-canvas">
                @if ($page->description !== null)
                    <p class="mb-6 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $page->description }}</p>
                @endif

                @if ($page->status === PageStatus::Deprecated)
                    <div class="mb-6 border-l-4 border-orange-500 bg-orange-50 px-4 py-3 text-sm text-orange-950 dark:bg-orange-950 dark:text-orange-100">
                        <p class="font-semibold">This page is deprecated.</p>
                        <p class="mt-1">Its guidance may be outdated. Verify the current source before relying on it.</p>
                    </div>
                @endif

                @if ($contentUnavailable)
                    <div class="mb-6 border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950 dark:bg-red-950 dark:text-red-100">
                        Stored page content is unavailable.
                    </div>
                @endif

                @if ($version?->scan_status === PageSecurityScanStatus::Warnings)
                    <div class="mb-6 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">
                        <p class="font-semibold">Security warnings recorded for this version.</p>
                        @if (is_array($version->scan_findings) && $version->scan_findings !== [])
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach ($version->scan_findings as $finding)
                                    <li>{{ $finding['message'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                @if ($page->type === PageType::Markdown)
                    <div class="artifactflow-markdown rounded-md border border-zinc-200 bg-white p-6 text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                        {!! $renderedMarkdown ?? '' !!}
                    </div>
                @else
                    <div class="space-y-4">
                        <div class="af-artifact-profile">
                            <div>
                                <p class="text-sm font-semibold text-zinc-950 dark:text-zinc-50">HTML artifact</p>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Stored as immutable version {{ $version?->version_number }}.</p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"><span class="font-semibold">Sandbox profile:</span> Scripts only · no same-origin, forms, top navigation, or outbound network.</p>
                            </div>
                        </div>
                        @if ($artifactPreviewUrl !== null)
                            <div
                                class="af-artifact-preview flex flex-col gap-2"
                                data-artifact-preview
                                data-artifact-preview-refresh-endpoint="{{ route('pages.artifact-preview-url', $page, false) }}"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    @include('pages.partials.artifact-sensitive-data-warning')
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800" data-artifact-fullscreen-toggle aria-expanded="false" hidden>
                                        <span data-artifact-fullscreen-label>Fullscreen</span>
                                    </button>
                                </div>
                                <iframe class="af-artifact-iframe h-[calc(100vh-13rem)] min-h-[38rem] w-full rounded-md border border-zinc-300 bg-white dark:border-zinc-700 dark:bg-zinc-900" data-artifact-preview-frame loading="lazy" referrerpolicy="no-referrer" sandbox="allow-scripts" allow="" src="{{ $artifactPreviewUrl }}" title="Artifact preview"></iframe>
                            </div>
                        @endif
                    </div>
                @endif
            </article>

            @if ($canMutateContent && $sourcePreview !== null)
                @include('pages.partials.content-dialog')
            @endif
            @include('pages.partials.metadata-dialog')
            @if ($canMoveWorkspace)
                @include('pages.partials.workspace-move-dialog')
            @endif
            @include('pages.partials.structure-dialog')
            @include('pages.partials.versions-dialog')
            @include('pages.partials.activity-dialog')
            @include('pages.partials.access-dialog')
            @include('pages.partials.lifecycle-dialog')
        </main>
    </div>
</x-layouts.app>
