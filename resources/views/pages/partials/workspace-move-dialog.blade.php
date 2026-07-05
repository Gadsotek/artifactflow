<dialog class="artifactflow-editor-dialog af-detail-dialog" data-editor-dialog id="page-workspace-move-dialog" aria-labelledby="page-workspace-move-dialog-title">
    <div class="artifactflow-editor-dialog-panel">
        <div class="af-dialog-header">
            <div>
                <p class="af-eyebrow">Workspace boundary</p>
                <h2 id="page-workspace-move-dialog-title">Move workspace</h2>
                <p>Move this page into another workspace where you can create pages.</p>
            </div>
            <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close workspace move">Close</button>
        </div>
        <div class="af-dialog-scroll af-dialog-columns">
            <section>
                <h3>What changes</h3>
                <div class="af-lifecycle-note">
                    <p>Moving a page changes its authorization context. To keep that boundary safe, artifactflow will:</p>
                    <ul class="mt-3 list-disc space-y-2 pl-5">
                        <li>Clear the current parent page because hierarchy is workspace-scoped.</li>
                        <li>Reuse or create the same category in the target workspace.</li>
                        <li>Reset explicit access grants and use the target workspace permissions.</li>
                        <li>Keep the page's global tags unchanged.</li>
                    </ul>
                </div>
            </section>

            <section>
                <h3>Move target</h3>
                <form class="mt-4 space-y-3" data-page-workspace-move-form method="POST" action="{{ route('pages.workspace.update', $page) }}">
                    @csrf
                    @method('PUT')
                    <label class="block">
                        <span class="text-sm font-medium">Target workspace</span>
                        <select class="mt-2 w-full" name="target_workspace_uid" required>
                            @foreach ($pageMoveTargets as $moveTarget)
                                <option data-move-target-workspace-option value="{{ $moveTarget->workspaceUid }}" @selected(old('target_workspace_uid') === $moveTarget->workspaceUid)>{{ $moveTarget->workspaceName }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">New owner</span>
                        <select class="mt-2 w-full" name="target_owner_user_uid" required>
                            @foreach ($pageMoveTargets as $moveTarget)
                                <optgroup label="{{ $moveTarget->workspaceName }}">
                                    @foreach ($moveTarget->owners as $moveOwner)
                                        <option data-move-target-owner-option data-move-target-owner-workspace-uid="{{ $moveTarget->workspaceUid }}" value="{{ $moveOwner->uid }}" @selected(old('target_owner_user_uid') === $moveOwner->uid)>{{ $moveOwner->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label class="af-confirm-check">
                        <input name="confirm_move" type="checkbox" value="1" required>
                        <span>I understand this will reset the page parent and explicit access grants.</span>
                    </label>
                    <button class="af-primary-button w-full" type="submit">Move page</button>
                </form>
            </section>
        </div>
    </div>
</dialog>
