<section aria-labelledby="passage-retrieval-heading" class="space-y-3 border-t border-zinc-200 pt-6 dark:border-zinc-800">
    <h2 id="passage-retrieval-heading" class="text-lg font-semibold">Passage retrieval for MCP</h2>
    <p class="max-w-prose text-sm">Enabling retrieval builds a private database index of current Markdown and static HTML. Coverage grows as the worker indexes existing pages. Turning it off stops retrieval immediately and queues the index for deletion. Enabling it again rebuilds from current versions. Backups follow your installation’s retention policy.</p>
    @if ($progress->enabled)
        <p>{{ $progress->indexed }} of {{ $progress->eligible }} current versions indexed. {{ $progress->limited }} limited. {{ $progress->failed }} awaiting retry.</p>
    @endif
    @if ($progress->retired > 0)
        <p>{{ $progress->retired }} {{ Str::plural('record', $progress->retired) }} awaiting cleanup.</p>
    @endif
    <form method="POST" action="{{ route('admin.settings.passage-retrieval') }}" class="flex flex-wrap items-center gap-3">
        @csrf
        @method('PUT')
        <input type="hidden" name="generation" value="{{ $progress->generation }}">
        <label for="passage-retrieval-enabled">Passage retrieval</label>
        <select id="passage-retrieval-enabled" name="enabled" class="af-input">
            <option value="0" @selected(!$progress->enabled)>Off</option>
            <option value="1" @selected($progress->enabled)>On</option>
        </select>
        <button class="af-primary-button" type="submit">Save passage retrieval</button>
    </form>
</section>
