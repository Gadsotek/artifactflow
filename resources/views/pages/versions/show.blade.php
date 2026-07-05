@use('App\Domain\PageCatalog\PageType')
@use('App\Domain\PageCatalog\PageVersionDiffLineKind')
<x-layouts.app title="Version {{ $inspection->version->version_number }} · {{ $page->title }}">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-[100rem] flex-wrap items-center justify-between gap-4 px-6 py-4">
                <div>
                    <p class="af-eyebrow">Version inspector</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">{{ $page->title }}</h1>
                    @if ($inspection->version->uid === $inspection->currentVersion->uid)
                        <p class="af-page-intro">Current version {{ $inspection->version->version_number }}</p>
                    @else
                        <p class="af-page-intro">Historical version {{ $inspection->version->version_number }} · Current version is {{ $inspection->currentVersion->version_number }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a class="af-secondary-button" href="#version-preview">Preview</a>
                    <a class="af-secondary-button" href="#version-changes">Changes</a>
                    <a class="af-primary-button" href="{{ route('pages.show', $page) }}">Back to current page</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[100rem] space-y-6 px-6 py-8">
            <section class="af-version-inspector-summary">
                <div>
                    <p class="af-eyebrow">Immutable snapshot</p>
                    <h2>Version {{ $inspection->version->version_number }}</h2>
                    <p>Changed by {{ $inspection->version->creator->name }} · {{ $inspection->version->created_at->toDayDateTimeString() }} · {{ $inspection->version->byte_size }} bytes · {{ $inspection->version->scan_status->value }}</p>
                    <code>SHA-256 {{ $inspection->version->content_hash }}</code>
                </div>
                <nav class="flex flex-wrap items-center gap-2" aria-label="Version navigation">
                    @if ($inspection->olderVersion !== null)
                        <a class="af-secondary-button" href="{{ route('pages.versions.show', [$page, $inspection->olderVersion]) }}">Older · v{{ $inspection->olderVersion->version_number }}</a>
                    @endif
                    @if ($inspection->newerVersion !== null)
                        <a class="af-secondary-button" href="{{ route('pages.versions.show', [$page, $inspection->newerVersion]) }}">Newer · v{{ $inspection->newerVersion->version_number }}</a>
                    @endif
                    @if ($inspection->canRestore)
                        <form method="POST" action="{{ route('pages.versions.restore', [$page, $inspection->version]) }}">
                            @csrf
                            <input type="hidden" name="current_version_uid" value="{{ $inspection->currentVersion->uid }}">
                            <button class="af-secondary-button" type="submit">Restore as new current version</button>
                        </form>
                    @endif
                </nav>
            </section>

            @if ($inspection->contentUnavailable)
                <div class="border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950 dark:bg-red-950 dark:text-red-100">
                    Stored version content is unavailable.
                </div>
            @endif

            <section class="af-document-canvas scroll-mt-6" id="version-preview">
                <div class="mb-5">
                    <p class="af-eyebrow">Preview</p>
                    <h2 class="text-lg font-semibold text-zinc-950 dark:text-zinc-50">Version {{ $inspection->version->version_number }} as rendered then</h2>
                </div>

                @if ($page->type === PageType::Markdown)
                    <div class="artifactflow-markdown rounded-md border border-zinc-200 bg-white p-6 text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">
                        {!! $inspection->renderedMarkdown ?? '' !!}
                    </div>
                @elseif ($inspection->artifactPreviewUrl !== null)
                    <div
                        class="af-artifact-preview flex flex-col gap-2"
                        data-artifact-preview
                        data-artifact-preview-refresh-endpoint="{{ route('pages.versions.artifact-preview-url', [$page, $inspection->version], false) }}"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            @include('pages.partials.artifact-sensitive-data-warning')
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">Historical HTML runs only on the isolated artifact origin.</span>
                        </div>
                        <iframe class="af-artifact-iframe h-[calc(100vh-16rem)] min-h-[38rem] w-full rounded-md border border-zinc-300 bg-white dark:border-zinc-700 dark:bg-zinc-900" data-artifact-preview-frame loading="lazy" referrerpolicy="no-referrer" sandbox="allow-scripts" allow="" src="{{ $inspection->artifactPreviewUrl }}" title="Historical artifact preview"></iframe>
                    </div>
                @endif
            </section>

            <section class="af-document-canvas scroll-mt-6" id="version-changes" data-version-diff>
                <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="af-eyebrow">Changes</p>
                        <h2 class="text-lg font-semibold text-zinc-950 dark:text-zinc-50">Version {{ $inspection->version->version_number }} compared with current version {{ $inspection->currentVersion->version_number }}</h2>
                    </div>
                    @if (!$inspection->diff->tooLarge)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $inspection->diff->addedLines }} added · {{ $inspection->diff->removedLines }} removed</p>
                    @endif
                </div>

                @if ($inspection->diff->tooLarge)
                    <div class="af-callout">This source is too large for an in-browser comparison. The historical preview remains available above.</div>
                @elseif ($inspection->comparisonUnavailable)
                    <div class="af-callout">The comparison is unavailable because one version could not be read.</div>
                @elseif ($inspection->diff->addedLines === 0 && $inspection->diff->removedLines === 0)
                    <div class="af-callout">This is the current version; there are no source changes to show.</div>
                @else
                    <div class="af-version-diff" role="table" aria-label="Source changes">
                        @foreach ($inspection->diff->lines as $line)
                            @if ($line->kind === PageVersionDiffLineKind::Omitted)
                                <div class="af-version-diff-line" data-diff-kind="omitted" role="row">
                                    <span role="cell"></span>
                                    <span role="cell"></span>
                                    <code role="cell">… {{ $line->omittedLineCount }} unchanged lines …</code>
                                </div>
                            @else
                                <div class="af-version-diff-line" data-diff-kind="{{ $line->kind->value }}" role="row">
                                    <span role="cell">{{ $line->oldLineNumber }}</span>
                                    <span role="cell">{{ $line->newLineNumber }}</span>
                                    <code role="cell"><span aria-hidden="true">{{ $line->kind === PageVersionDiffLineKind::Added ? '+' : ($line->kind === PageVersionDiffLineKind::Removed ? '−' : ' ') }}</span>{{ $line->content }}</code>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </section>
        </main>
    </div>
</x-layouts.app>
