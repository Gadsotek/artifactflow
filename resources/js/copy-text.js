for (const button of document.querySelectorAll('[data-copy-text]')) {
  button.addEventListener('click', async () => {
    const source = document.getElementById(button.dataset.copyText);
    const status = button
      .closest('[data-copy-text-control]')
      ?.querySelector('[data-copy-text-status]');
    if (!source || !status) return;
    try {
      await navigator.clipboard.writeText(source.textContent);
      status.textContent = 'Copied';
    } catch {
      status.textContent = 'Copy unavailable. Select the text and copy it manually.';
    }
  });
}
