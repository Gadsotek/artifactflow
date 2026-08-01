@use('App\Domain\ExternalSharing\ExternalPagePresentation')
@use('App\Domain\ExternalSharing\ExternalShareMode')
@use('App\Domain\PageCatalog\PageStatus')

<div class="af-external-viewer-content">
    <header class="af-external-page-header">
        <div>
            <p class="af-eyebrow">External artifact · Live · latest version</p>
            <h1 data-external-share-title>{{ $viewer->context->page->title }}</h1>
            <div class="af-external-metadata">
                <span>Version {{ $viewer->version->version_number }}</span>
                @if ($viewer->context->share->mode === ExternalShareMode::ExpiresAt && $viewer->context->share->expires_at !== null)
                    <span>
                        Expires
                        <time
                            data-external-share-local-time="date-time"
                            datetime="{{ $viewer->context->share->expires_at->toISOString() }}"
                        >
                            {{ $viewer->context->share->expires_at->toISOString() }}
                        </time>
                    </span>
                @endif
            </div>
        </div>
    </header>

    <main class="af-external-content">
        @if ($viewer->context->page->status === PageStatus::Deprecated)
            <div class="af-external-warning">
                <p class="font-semibold">This artifact is deprecated.</p>
                <p class="mt-1">Its content may be outdated.</p>
            </div>
        @endif

        @if ($viewer->presentation === ExternalPagePresentation::Markdown)
            <article class="artifactflow-markdown af-external-document">
                {!! $viewer->renderedMarkdown ?? '' !!}
            </article>
        @else
            <section
                class="af-artifact-preview flex flex-col gap-3"
                data-artifact-preview
                @if ($viewer->presentation === ExternalPagePresentation::SandboxedHtml)
                    data-artifact-preview-refresh-endpoint="{{ route('external-shares.artifact-preview-url', [
                        'externalShareUid' => $viewer->context->share->uid,
                        'externalShareSessionUid' => $viewer->context->session->uid,
                    ], false) }}"
                @endif
            >
                <div class="af-external-preview-toolbar">
                    <p>
                        This artifact runs inside ArtifactFlow's isolated preview boundary.
                    </p>
                    <button
                        class="af-secondary-button"
                        data-artifact-fullscreen-toggle
                        type="button"
                        aria-expanded="false"
                        hidden
                    >
                        <span data-artifact-fullscreen-label>Fullscreen</span>
                    </button>
                </div>
                <iframe
                    class="af-artifact-iframe af-external-preview-frame"
                    data-artifact-preview-frame
                    loading="eager"
                    referrerpolicy="no-referrer"
                    sandbox="{{ $viewer->presentation === ExternalPagePresentation::SandboxedHtml ? 'allow-scripts' : '' }}"
                    allow=""
                    src="{{ $viewer->artifactPreviewUrl }}"
                    title="{{ $viewer->presentation === ExternalPagePresentation::SandboxedHtml ? 'Artifact preview' : 'Image preview' }}"
                ></iframe>
            </section>
        @endif
    </main>
</div>
