@use('App\Domain\ExternalSharing\ExternalShareMode')
@use('App\Domain\ExternalSharing\ExternalShareStatus')
@use('App\Domain\PageCatalog\PageStatus')

<dialog
    class="artifactflow-editor-dialog af-detail-dialog"
    data-editor-dialog
    data-external-share-management
    id="page-external-share-dialog"
    aria-labelledby="page-external-share-dialog-title"
>
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Bearer capability</p>
                <h2 id="page-external-share-dialog-title">Share externally</h2>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close external sharing">Close</button>
        </div>

        <div class="af-dialog-scroll space-y-6">
            @if (!$externalSharingEnabled)
                <div class="af-callout" data-external-sharing-disabled>
                    External sharing is disabled for this installation. A System Admin can enable it in installation settings.
                </div>
            @elseif ($page->status === PageStatus::Archived)
                <div class="af-callout" data-external-sharing-disabled>
                    Archived pages cannot be shared externally.
                </div>
            @else
                <section aria-labelledby="external-share-create-title">
                    <h3 class="text-sm font-semibold text-zinc-950 dark:text-zinc-50" id="external-share-create-title">Create an external link</h3>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        The recipient needs no account. Anyone holding the link can open the latest version of this artifact.
                    </p>

                    <form
                        class="mt-4 space-y-4"
                        data-external-share-form
                        method="POST"
                        action="{{ route('pages.external-shares.store', $page) }}"
                    >
                        @csrf
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Link mode</legend>
                            <label class="flex items-start gap-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
                                <input
                                    class="mt-1"
                                    data-external-share-mode
                                    name="mode"
                                    type="radio"
                                    value="{{ ExternalShareMode::ExpiresAt->value }}"
                                >
                                <span>
                                    <span class="block text-sm font-medium text-zinc-950 dark:text-zinc-50">Expires at</span>
                                    <span class="mt-1 block text-xs text-zinc-500">May start multiple short viewing sessions until the selected date and time.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
                                <input
                                    class="mt-1"
                                    data-external-share-mode
                                    name="mode"
                                    type="radio"
                                    value="{{ ExternalShareMode::OneTime->value }}"
                                >
                                <span>
                                    <span class="block text-sm font-medium text-zinc-950 dark:text-zinc-50">One time</span>
                                    <span class="mt-1 block text-xs text-zinc-500">Can begin exactly one viewing session and remains usable until redeemed or revoked.</span>
                                </span>
                            </label>
                        </fieldset>

                        <label class="block" data-external-share-expiry-field hidden>
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Expiry</span>
                            <input
                                class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50"
                                data-external-share-expiry
                                data-external-share-max-expiry-hours="{{ $externalShareMaxExpiryHours }}"
                                name="expires_at"
                                type="datetime-local"
                            >
                            <span class="mt-1 block text-xs text-zinc-500">
                                Maximum lifetime: {{ $externalShareMaxExpiryHours }} hours. The browser submits this in your local timezone.
                            </span>
                        </label>

                        <p class="text-xs text-amber-700 dark:text-amber-300">
                            Treat the complete link like a password. ArtifactFlow stores only a verifier and cannot reveal it again.
                        </p>
                        <div class="flex flex-wrap items-center gap-3">
                            <button class="af-primary-button" type="submit">Create external link</button>
                            <span class="text-sm text-zinc-600 dark:text-zinc-400" data-external-share-form-status role="status" aria-live="polite"></span>
                        </div>
                    </form>

                    <div
                        class="mt-4 rounded-md border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950"
                        data-external-share-secret
                        hidden
                    >
                        <p class="text-sm font-semibold text-emerald-950 dark:text-emerald-100">External link created</p>
                        <p class="mt-1 text-xs text-emerald-800 dark:text-emerald-200">
                            Copy it now. It will not appear in this inventory or any later response.
                        </p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <input
                                class="min-w-0 flex-1 rounded-md border border-emerald-300 bg-white px-3 py-2 font-mono text-xs text-zinc-950 dark:border-emerald-700 dark:bg-zinc-900 dark:text-zinc-50"
                                data-external-share-secret-url
                                type="text"
                                readonly
                                aria-label="New external share link"
                            >
                            <button class="af-secondary-button" data-copy-external-share-link type="button">Copy link</button>
                        </div>
                        <p class="mt-2 text-xs text-emerald-800 dark:text-emerald-200" data-external-share-copy-status role="status" aria-live="polite"></p>
                    </div>
                </section>
            @endif

            <section class="border-t border-zinc-200 pt-5 dark:border-zinc-800" aria-labelledby="external-share-inventory-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-950 dark:text-zinc-50" id="external-share-inventory-title">External share inventory</h3>
                        <p class="mt-1 text-xs text-zinc-500">Secrets are never shown after creation.</p>
                    </div>
                    <span class="text-xs text-zinc-500" data-external-share-active-count>
                        {{ $externalShareInventory->activeCount }} active
                    </span>
                </div>

                <div class="mt-4 space-y-3" data-external-share-inventory>
                    @forelse ($externalShareInventory->items as $externalShare)
                        <article class="rounded-md border border-zinc-200 p-4 dark:border-zinc-800" data-external-share-row="{{ $externalShare->uid }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-zinc-950 dark:text-zinc-50">
                                        {{ $externalShare->mode === ExternalShareMode::OneTime ? 'One time' : 'Expires at' }}
                                        · {{ ucfirst($externalShare->status->value) }}
                                    </p>
                                    <p class="mt-1 font-mono text-xs text-zinc-500">{{ $externalShare->uid }}</p>
                                </div>
                                @if ($externalShare->revokedAt === null)
                                    <form method="POST" action="{{ route('pages.external-shares.destroy', [$page, $externalShare->uid]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="af-secondary-button" type="submit">Revoke</button>
                                    </form>
                                @endif
                            </div>
                            <dl class="mt-3 grid gap-2 text-xs text-zinc-600 dark:text-zinc-400 sm:grid-cols-2">
                                <div>
                                    <dt class="font-medium text-zinc-800 dark:text-zinc-200">Created</dt>
                                    <dd>{{ $externalShare->createdAt->format('M j, Y H:i T') }} by {{ $externalShare->createdByName }}</dd>
                                </div>
                                @if ($externalShare->expiresAt !== null)
                                    <div>
                                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">Expires</dt>
                                        <dd>
                                            <time datetime="{{ $externalShare->expiresAt->toISOString() }}">
                                                {{ $externalShare->expiresAt->format('M j, Y H:i T') }}
                                            </time>
                                            · {{ $externalShare->expiresAt->diffForHumans() }}
                                        </dd>
                                    </div>
                                @endif
                                @if ($externalShare->firstViewedAt !== null)
                                    <div>
                                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">First viewed</dt>
                                        <dd>{{ $externalShare->firstViewedAt->format('M j, Y H:i T') }}</dd>
                                    </div>
                                @endif
                                @if ($externalShare->lastViewedAt !== null)
                                    <div>
                                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">Last viewed</dt>
                                        <dd>{{ $externalShare->lastViewedAt->format('M j, Y H:i T') }}</dd>
                                    </div>
                                @endif
                                @if ($externalShare->redeemedAt !== null)
                                    <div>
                                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">Redeemed</dt>
                                        <dd>{{ $externalShare->redeemedAt->format('M j, Y H:i T') }}</dd>
                                    </div>
                                @endif
                                @if ($externalShare->status === ExternalShareStatus::Redeemed && $externalShare->hasOpenViewSession)
                                    <div>
                                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">Viewing session</dt>
                                        <dd>Session still open · revoke to close</dd>
                                    </div>
                                @endif
                                @if ($externalShare->revokedAt !== null)
                                    <div>
                                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">Revoked</dt>
                                        <dd>
                                            {{ $externalShare->revokedAt->format('M j, Y H:i T') }}
                                            @if ($externalShare->revokedByName !== null)
                                                by {{ $externalShare->revokedByName }}
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </article>
                    @empty
                        <p class="rounded-md border border-dashed border-zinc-300 px-4 py-5 text-sm text-zinc-500 dark:border-zinc-700" data-external-share-empty>
                            No external links have been created for this page.
                        </p>
                    @endforelse
                </div>

                @if ($externalShareInventory->hasMore)
                    <p class="mt-3 text-xs text-zinc-500">Only the 50 newest links are shown.</p>
                @endif
            </section>
        </div>
    </div>
</dialog>
