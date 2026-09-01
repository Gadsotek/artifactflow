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
                @if (in_array($viewer->presentation, [ExternalPagePresentation::SandboxedHtml, ExternalPagePresentation::TypedSpreadsheet], true))
                    data-artifact-preview-refresh-endpoint="{{ route('external-shares.artifact-preview-url', [
                        'externalShareUid' => $viewer->context->share->uid,
                        'externalShareSessionUid' => $viewer->context->session->uid,
                    ], false) }}"
                @endif
                @if ($viewer->presentation === ExternalPagePresentation::NativePdf)
                    data-pdf-preview
                @elseif ($viewer->presentation === ExternalPagePresentation::TypedSpreadsheet)
                    data-xlsx-preview
                @elseif ($viewer->presentation === ExternalPagePresentation::DerivedDocumentPdf)
                    data-docx-preview
                @endif
            >
                <div class="af-external-preview-toolbar">
                    @if ($viewer->presentation === ExternalPagePresentation::NativePdf)
                        <p>
                            Viewing this PDF is download-equivalent. Your browser may expose save, print, and copy controls.
                        </p>
                    @elseif ($viewer->presentation === ExternalPagePresentation::TypedSpreadsheet)
                        <p>
                            Read-only Excel preview. Formulas are not recalculated and original workbook bytes are never shared.
                        </p>
                    @elseif ($viewer->presentation === ExternalPagePresentation::DerivedDocumentPdf)
                        <p>
                            Searchable PDF preview derived from the retained Word document. The original DOCX is not shared.
                        </p>
                    @else
                        <p>
                            This artifact runs inside ArtifactFlow's isolated preview boundary.
                        </p>
                    @endif
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
                    @if (!in_array($viewer->presentation, [ExternalPagePresentation::NativePdf, ExternalPagePresentation::DerivedDocumentPdf], true))
                        sandbox="{{ $viewer->presentation === ExternalPagePresentation::TypedSpreadsheet ? 'allow-scripts allow-popups allow-popups-to-escape-sandbox' : ($viewer->presentation === ExternalPagePresentation::SandboxedHtml ? 'allow-scripts' : '') }}"
                    @endif
                    allow=""
                    src="{{ $viewer->artifactPreviewUrl }}"
                    title="{{ match ($viewer->presentation) {
                        ExternalPagePresentation::SandboxedHtml => 'Artifact preview',
                        ExternalPagePresentation::ScriptlessImage => 'Image preview',
                        ExternalPagePresentation::NativePdf => 'PDF preview',
                        ExternalPagePresentation::TypedSpreadsheet => 'Read-only Excel preview',
                        ExternalPagePresentation::DerivedDocumentPdf => 'Word document PDF preview',
                        ExternalPagePresentation::Markdown => 'Document preview',
                    } }}"
                ></iframe>
            </section>
        @endif
    </main>
</div>
