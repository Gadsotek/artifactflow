<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-metadata-dialog" aria-labelledby="page-metadata-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Page details</p>
                <h2 id="page-metadata-dialog-title">Metadata</h2>
                <p>Core identity, ownership, discovery context, and editable descriptive fields.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close metadata">Close</button>
        </div>
        <div class="af-dialog-scroll af-dialog-columns">
            <section>
                <h3>Current metadata</h3>
                <dl class="af-metadata-list">
                    <div><dt>Status</dt><dd>{{ $page->status->value }}</dd></div>
                    <div><dt>Type</dt><dd>{{ $page->type->value }}</dd></div>
                    <div><dt>Owner</dt><dd>{{ $page->owner->name }}</dd></div>
                    <div><dt>Version</dt><dd>{{ $version?->version_number }}</dd></div>
                    <div><dt>Scan</dt><dd>{{ $version?->scan_status->value }}</dd></div>
                    @if ($category !== null)
                        <div><dt>Category</dt><dd>{{ $category->name }}</dd></div>
                    @endif
                    <div><dt>Created</dt><dd>{{ $page->created_at->toDateString() }}</dd></div>
                    <div><dt>Updated</dt><dd>{{ $page->updated_at->toDateString() }}</dd></div>
                </dl>
                <div class="mt-6">
                    <h3>Tags</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($tags as $tag)
                            <span class="af-soft-chip">{{ $tag->name }}</span>
                        @empty
                            <span class="text-sm text-zinc-500">None</span>
                        @endforelse
                    </div>
                </div>
            </section>

            @if ($canEdit)
                <section>
                    <h3>Edit metadata</h3>
                    <form class="mt-4 space-y-3" method="POST" action="{{ route('pages.metadata.update', $page) }}">
                        @csrf
                        @method('PUT')
                        <label class="block">
                            <span class="text-sm font-medium">Title</span>
                            <input class="mt-2 w-full" name="title" type="text" value="{{ old('title', $page->title) }}" required>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Description</span>
                            <textarea class="mt-2 min-h-24 w-full" name="description">{{ old('description', $page->description) }}</textarea>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium">Owner</span>
                                <select class="mt-2 w-full" name="owner_user_uid" required>
                                    @foreach ($metadataOwners as $owner)
                                        <option value="{{ $owner->uid }}" @selected(old('owner_user_uid', $page->owner_user_uid) === $owner->uid)>{{ $owner->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Category</span>
                                <select class="mt-2 w-full" name="category_uid">
                                    <option value="">None</option>
                                    @foreach ($metadataCategories as $metadataCategory)
                                        <option value="{{ $metadataCategory->uid }}" @selected(old('category_uid', $page->category_uid) === $metadataCategory->uid)>{{ $metadataCategory->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-sm font-medium">Parent page</span>
                            <select class="mt-2 w-full" name="parent_page_uid">
                                <option value="">None</option>
                                @foreach ($metadataParentPages as $metadataParentPage)
                                    <option value="{{ $metadataParentPage->uid }}" @selected(old('parent_page_uid', $page->parent_page_uid) === $metadataParentPage->uid)>{{ $metadataParentPage->title }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Tags</span>
                            <input class="mt-2 w-full" name="tags" type="text" value="{{ old('tags', $metadataTagNames) }}">
                        </label>
                        <button class="af-primary-button w-full" type="submit">Save metadata</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
</dialog>
