function fallbackCopy(value) {
  const textarea = document.createElement('textarea');
  textarea.value = value;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();

  try {
    return document.execCommand('copy');
  } finally {
    textarea.remove();
  }
}

async function copyText(value) {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(value);

      return true;
    } catch {
      // Fall through for browsers or policies that deny the Clipboard API.
    }
  }

  return fallbackCopy(value);
}

for (const trigger of document.querySelectorAll('[data-copy-page-link]')) {
  trigger.addEventListener('click', async () => {
    const url = trigger.getAttribute('data-copy-page-link-url');
    const control = trigger.closest('[data-copy-page-link-control]');
    const status = control?.querySelector('[data-copy-page-link-status]');

    if (!url || !(trigger instanceof HTMLButtonElement)) {
      return;
    }

    trigger.disabled = true;

    try {
      const copied = await copyText(url);

      if (status instanceof HTMLElement) {
        status.textContent = copied
          ? 'Page link copied.'
          : 'Copy failed. Use the page URL from your browser.';
      }
    } finally {
      trigger.disabled = false;
    }
  });

  trigger.setAttribute('data-copy-page-link-ready', '');
}
