<x-mail::message>
# Workspace invitation

{{ $inviterNameMarkdown }} invited you to the {{ $workspaceNameMarkdown }} workspace as {{ $roleLabel }}.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires on {{ $expiresAt->format('Y-m-d H:i T') }}.

If you were not expecting this invitation, you can ignore this email.
</x-mail::message>
