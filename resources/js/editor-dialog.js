function closeDialog(dialog) {
  if (dialog instanceof HTMLDialogElement && dialog.open) {
    dialog.close();
  }
}

for (const trigger of document.querySelectorAll('[data-open-editor-dialog]')) {
  trigger.addEventListener('click', () => {
    const dialogId = trigger.getAttribute('data-open-editor-dialog');

    if (!dialogId) {
      return;
    }

    const dialog = document.getElementById(dialogId);

    if (!(dialog instanceof HTMLDialogElement)) {
      return;
    }

    dialog.showModal();
    dialog
      .querySelector('[data-source-editor-mount]')
      ?.dispatchEvent(new Event('artifactflow:editor-visible'));
  });

  trigger.setAttribute('data-editor-dialog-trigger-ready', '');
}

for (const dialog of document.querySelectorAll('[data-editor-dialog]')) {
  if (dialog instanceof HTMLDialogElement && dialog.hasAttribute('data-auto-open-editor-dialog')) {
    dialog.showModal();
  }

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      closeDialog(dialog);
    }
  });

  for (const closeButton of dialog.querySelectorAll('[data-close-editor-dialog]')) {
    closeButton.addEventListener('click', () => closeDialog(dialog));
  }
}
