@if ($provenance === null || $provenance->versionIngest === null)
    <div class="af-callout">No provenance record is available for this version.</div>
@else
    <div class="space-y-5">
        <div class="af-callout">
            Producer values are declared metadata, not independent verification.
            Evidence: {{ ucfirst(str_replace('_', ' ', $provenance->strongestEvidence?->value ?? 'none')) }}.
            Completeness: {{ $provenance->completeness->value }}.
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Imported via</dt>
                <dd>{{ ucfirst($provenance->versionIngest->ingestMethod->value) }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-zinc-950 dark:text-zinc-50">ArtifactFlow actor</dt>
                <dd>{{ $provenance->versionIngest->actorName }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Recorded</dt>
                <dd>{{ $provenance->versionIngest->recordedAt->toDayDateTimeString() }}</dd>
            </div>
            @if ($provenance->versionIngest->mcpReportedClientName !== null)
                <div>
                    <dt class="font-semibold text-zinc-950 dark:text-zinc-50">MCP-reported client</dt>
                    <dd>{{ $provenance->versionIngest->mcpReportedClientName }}{{ $provenance->versionIngest->mcpReportedClientVersion !== null ? ' ' . $provenance->versionIngest->mcpReportedClientVersion : '' }}</dd>
                </div>
            @endif
            @if ($provenance->inheritsContentOrigin())
                <div>
                    <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Content origin</dt>
                    <dd>Inherited from version {{ $provenance->effectiveContentOrigin?->versionNumber }}</dd>
                </div>
            @endif
        </dl>

        @forelse ($provenance->contentOriginProducers() as $producer)
            <article class="rounded-md border border-zinc-200 p-4 dark:border-zinc-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="af-eyebrow">{{ strtoupper($producer->kind->value) }} producer</p>
                        <h3 class="font-semibold text-zinc-950 dark:text-zinc-50">{{ $producer->displayName() }}</h3>
                    </div>
                    <span class="af-role-badge">{{ ucfirst(str_replace('_', ' ', $producer->evidenceType->value)) }}</span>
                </div>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    @if ($producer->providerKey !== null)
                        <div>
                            <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Provider</dt>
                            <dd>{{ $producer->providerKey }}</dd>
                        </div>
                    @endif
                    @if ($producer->modelId !== null)
                        <div>
                            <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Model ID</dt>
                            <dd><code>{{ $producer->modelId }}</code></dd>
                        </div>
                    @endif
                    @if ($producer->modelVersion !== null)
                        <div>
                            <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Model version</dt>
                            <dd>{{ $producer->modelVersion }}</dd>
                        </div>
                    @endif
                    @if ($producer->generatedAt !== null)
                        <div>
                            <dt class="font-semibold text-zinc-950 dark:text-zinc-50">Generated</dt>
                            <dd>{{ $producer->generatedAt->toDayDateTimeString() }}</dd>
                        </div>
                    @endif
                </dl>
                @if ($producer->references !== [])
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($producer->references as $reference)
                            <li>
                                <span class="font-semibold">{{ ucfirst($reference->kind->value) }}:</span>
                                @if ($reference->url !== null)
                                    <a class="underline" href="{{ $reference->url }}" target="_blank" rel="noopener noreferrer">{{ $reference->externalRef ?? $reference->url }}</a>
                                @elseif ($reference->externalRef !== null)
                                    <span>{{ $reference->externalRef }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <div class="af-callout">No producer was declared for this version. ArtifactFlow does not guess a provider or model.</div>
        @endforelse
    </div>
@endif
