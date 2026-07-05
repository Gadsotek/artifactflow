@use('App\Domain\PageCatalog\PageStatus')
@if ($canEdit || $canArchive || $canDelete)
    <dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-lifecycle-dialog" aria-labelledby="page-lifecycle-dialog-title">
        <div class="artifactflow-editor-dialog-panel">
            <div class="af-dialog-header">
                <div>
                    <p class="af-eyebrow">State and retention</p>
                    <h2 id="page-lifecycle-dialog-title">Lifecycle</h2>
                    <p>Change status markers, archive reversibly, or perform an authorized irreversible deletion.</p>
                </div>
                <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close lifecycle controls">Close</button>
            </div>
            <div class="af-dialog-scroll af-lifecycle-grid">
                @if ($canArchive)
                    <section>
                        <h3>{{ $page->status === PageStatus::Archived ? 'Unarchive page' : 'Archive page' }}</h3>
                        @if ($page->status === PageStatus::Archived)
                            <form class="space-y-3" method="POST" action="{{ route('pages.unarchive', $page) }}">
                                @csrf
                                <label class="flex items-start gap-2 text-sm">
                                    <input class="mt-1" name="confirmed" value="1" type="checkbox" required>
                                    <span>Unarchiving returns this page to draft and makes it eligible for normal discovery again.</span>
                                </label>
                                <button class="af-secondary-button w-full" type="submit">Unarchive page</button>
                            </form>
                        @else
                            <form class="space-y-3" method="POST" action="{{ route('pages.archive', $page) }}">
                                @csrf
                                <label class="flex items-start gap-2 text-sm">
                                    <input class="mt-1" name="confirmed" value="1" type="checkbox" required>
                                    <span>Archiving is reversible, but hides this page from default lists and search.</span>
                                </label>
                                <button class="af-secondary-button w-full" type="submit">Archive page</button>
                            </form>
                        @endif
                    </section>
                @endif

                @if ($canEdit)
                    <section>
                        <h3>Status marker</h3>
                        <div class="space-y-3">
                            @if ($page->status === PageStatus::Draft)
                                <form method="POST" action="{{ route('pages.mark-approved', $page) }}">
                                    @csrf
                                    <button class="af-primary-button w-full" type="submit">Mark approved</button>
                                </form>
                            @elseif ($page->status === PageStatus::Approved)
                                <form method="POST" action="{{ route('pages.return-to-draft', $page) }}">
                                    @csrf
                                    <button class="af-secondary-button w-full" type="submit">Return to draft</button>
                                </form>
                                <form method="POST" action="{{ route('pages.deprecate', $page) }}">
                                    @csrf
                                    <button class="af-warning-button w-full" type="submit">Deprecate page</button>
                                </form>
                            @elseif ($page->status === PageStatus::Deprecated)
                                <form method="POST" action="{{ route('pages.restore-to-draft', $page) }}">
                                    @csrf
                                    <button class="af-warning-button w-full" type="submit">Restore to draft</button>
                                </form>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($canDelete)
                    <section class="af-danger-zone">
                        <h3>Delete permanently</h3>
                        <form class="space-y-3" method="POST" action="{{ route('pages.destroy', $page) }}">
                            @csrf
                            @method('DELETE')
                            <label class="block">
                                <span class="text-xs font-medium">Type the page title to confirm</span>
                                <input class="mt-2 w-full" name="confirmation" type="text" autocomplete="off" aria-label="Type {{ $page->title }} to confirm permanent deletion">
                            </label>
                            <button class="af-danger-button w-full" type="submit">Delete page</button>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </dialog>
@endif
