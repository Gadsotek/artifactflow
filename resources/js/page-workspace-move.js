function initialisePageWorkspaceMove(form) {
  const workspaceSelect = form.querySelector('[name="target_workspace_uid"]');
  const ownerSelect = form.querySelector('[name="target_owner_user_uid"]');

  if (
    !(workspaceSelect instanceof HTMLSelectElement) ||
    !(ownerSelect instanceof HTMLSelectElement)
  ) {
    return;
  }

  const syncOwnerOptions = () => {
    const workspaceUid = workspaceSelect.value;
    let firstAvailableOwner = null;

    for (const option of Array.from(ownerSelect.options)) {
      const optionWorkspaceUid = option.getAttribute('data-move-target-owner-workspace-uid');
      const isAvailable = optionWorkspaceUid === workspaceUid;

      option.disabled = !isAvailable;
      option.hidden = !isAvailable;

      if (isAvailable && firstAvailableOwner === null) {
        firstAvailableOwner = option;
      }
    }

    if (
      ownerSelect.selectedOptions[0]?.disabled &&
      firstAvailableOwner instanceof HTMLOptionElement
    ) {
      ownerSelect.value = firstAvailableOwner.value;
    }
  };

  workspaceSelect.addEventListener('change', syncOwnerOptions);
  syncOwnerOptions();
}

for (const form of document.querySelectorAll('[data-page-workspace-move-form]')) {
  initialisePageWorkspaceMove(form);
}
