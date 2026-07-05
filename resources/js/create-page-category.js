function initialiseCreatePageCategory(root) {
  const workspaceSelect = root.querySelector('[data-create-page-workspace-select]');
  const categorySelect = root.querySelector('[data-create-page-category-select]');
  const categoryName = root.querySelector('[data-create-page-category-name]');
  const parentSelect = root.querySelector('[data-create-page-parent-select]');
  const openButton = root.querySelector('[data-open-editor-dialog="page-category-create-dialog"]');
  const dialog = document.getElementById('page-category-create-dialog');
  const dialogForm = dialog?.querySelector('[data-create-page-category-form]');
  const dialogInput = dialog?.querySelector('[data-create-page-category-input]');
  const workspaceName = dialog?.querySelector('[data-create-page-category-workspace-name]');

  if (
    !(workspaceSelect instanceof HTMLSelectElement) ||
    !(categorySelect instanceof HTMLSelectElement) ||
    !(categoryName instanceof HTMLInputElement) ||
    !(openButton instanceof HTMLButtonElement) ||
    !(dialog instanceof HTMLDialogElement) ||
    !(dialogForm instanceof HTMLFormElement) ||
    !(dialogInput instanceof HTMLInputElement) ||
    !(workspaceName instanceof HTMLElement)
  ) {
    return;
  }

  const draftOption = () => categorySelect.querySelector('[data-create-page-category-option]');

  const updateWorkspace = () => {
    const selectedWorkspace = workspaceSelect.selectedOptions[0];
    workspaceName.textContent = selectedWorkspace?.textContent?.trim() ?? 'the selected workspace';

    for (const option of categorySelect.querySelectorAll(
      '[data-create-page-category-workspace-uid]',
    )) {
      if (!(option instanceof HTMLOptionElement)) {
        continue;
      }

      const available = option.dataset.createPageCategoryWorkspaceUid === workspaceSelect.value;
      option.disabled = !available;
      option.hidden = !available;
    }

    const selectedCategory = categorySelect.selectedOptions[0];

    if (selectedCategory?.disabled) {
      categorySelect.value = '';
      categoryName.value = '';

      if (selectedCategory.hasAttribute('data-create-page-category-option')) {
        selectedCategory.remove();
      }
    }

    if (parentSelect instanceof HTMLSelectElement) {
      for (const option of parentSelect.querySelectorAll(
        '[data-create-page-parent-workspace-uid]',
      )) {
        if (!(option instanceof HTMLOptionElement)) {
          continue;
        }

        const available = option.dataset.createPageParentWorkspaceUid === workspaceSelect.value;
        option.disabled = !available;
        option.hidden = !available;
      }

      if (parentSelect.selectedOptions[0]?.disabled) {
        parentSelect.value = '';
      }
    }
  };

  openButton.addEventListener('click', () => {
    dialogInput.value = categoryName.value;
    dialogInput.setCustomValidity('');
  });

  dialogInput.addEventListener('input', () => dialogInput.setCustomValidity(''));

  dialogForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const name = dialogInput.value.trim();

    if (name === '') {
      dialogInput.setCustomValidity('Enter a category name.');
      dialogInput.reportValidity();

      return;
    }

    let option = draftOption();

    if (!(option instanceof HTMLOptionElement)) {
      option = document.createElement('option');
      option.dataset.createPageCategoryOption = '';
      option.value = '';
      categorySelect.append(option);
    }

    option.textContent = `${name} (new)`;
    option.dataset.createPageCategoryWorkspaceUid = workspaceSelect.value;
    option.selected = true;
    categoryName.value = name;
    dialog.close();
    categorySelect.focus();
  });

  categorySelect.addEventListener('change', () => {
    const selectedCategory = categorySelect.selectedOptions[0];

    if (selectedCategory?.hasAttribute('data-create-page-category-option')) {
      return;
    }

    categoryName.value = '';
    draftOption()?.remove();
  });

  workspaceSelect.addEventListener('change', updateWorkspace);
  updateWorkspace();
}

for (const root of document.querySelectorAll('[data-create-page-category]')) {
  initialiseCreatePageCategory(root);
}
