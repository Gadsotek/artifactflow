<x-mail::message>
# You've been added to a workspace

{{ $addedByNameMarkdown }} added you to the {{ $workspaceNameMarkdown }} workspace as {{ $roleLabel }}. There is nothing to accept — you already have access.

<x-mail::button :url="$workspaceUrl">
Open ArtifactFlow
</x-mail::button>

Sign in and switch to the {{ $workspaceNameMarkdown }} workspace to get started.

If you believe this was a mistake, you can leave the workspace from its member settings.
</x-mail::message>
