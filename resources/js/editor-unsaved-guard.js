export function initialiseUnsavedGuard(form) {
  const signature = () =>
    JSON.stringify(
      [...new FormData(form)].map(([key, value]) => [
        key,
        value instanceof File
          ? value.name === ''
            ? null
            : [value.name, value.size, value.lastModified]
          : value,
      ]),
    );
  let baseline = signature();
  let leavingAfterSave = false;
  const hasChanges = () => !leavingAfterSave && signature() !== baseline;

  window.addEventListener('beforeunload', (event) => {
    if (!hasChanges()) return;
    event.preventDefault();
    event.returnValue = '';
  });
  form.addEventListener('submit', (event) => {
    if (!event.defaultPrevented) leavingAfterSave = true;
  });
  form.addEventListener('artifactflow:editor-saved', () => {
    baseline = signature();
    leavingAfterSave = true;
  });
  form.closest('dialog')?.addEventListener('artifactflow:before-editor-close', (event) => {
    if (
      hasChanges() &&
      !window.confirm(
        'Close the editor? Your unsaved changes will stay here until you leave this page.',
      )
    )
      event.preventDefault();
  });
  form.querySelector('[data-copy-draft]')?.addEventListener('click', async () => {
    const textarea = form.querySelector('[data-editor-textarea]');
    const status = form.querySelector('[data-draft-copy-status]');
    try {
      await navigator.clipboard.writeText(textarea.value);
      status.textContent = 'Draft copied';
    } catch {
      status.textContent =
        'Copy unavailable. Switch to source view and select your draft to copy it.';
    }
  });
  form.setAttribute('data-editor-unsaved-guard', 'ready');
}
