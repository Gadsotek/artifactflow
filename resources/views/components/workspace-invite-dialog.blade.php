@props([
    'dialogId',
    'workspaceUid',
    'roles',
    'returnTo' => null,
])

<dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Workspace access</p>
                <h2 id="{{ $dialogId }}-title">Invite teammate</h2>
                <p>Invite a teammate by email and choose their starting role.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close invitation form">Close</button>
        </div>
        <form class="grid gap-4 p-6" method="POST" action="{{ route('workspace-invitations.store', $workspaceUid) }}">
            @csrf
            @if ($returnTo !== null)
                <input name="return_to" type="hidden" value="{{ $returnTo }}">
            @endif
            <label>
                <span class="text-sm font-medium">Email address</span>
                <input class="mt-2 w-full" name="email" type="email" placeholder="teammate@example.com" required>
            </label>
            <label>
                <span class="text-sm font-medium">Workspace role</span>
                <select class="mt-2 w-full" name="role" required>
                    @foreach ($roles as $invitationRole)
                        <option value="{{ $invitationRole->value }}">{{ ucfirst($invitationRole->value) }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex justify-end">
                <button class="af-primary-button" type="submit">Send invitation</button>
            </div>
        </form>
    </div>
</dialog>
