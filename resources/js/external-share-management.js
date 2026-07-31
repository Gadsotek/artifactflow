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

function formattedDate(value) {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

function appendText(parent, text, className = '') {
  const element = document.createElement('p');
  element.textContent = text;

  if (className) {
    element.className = className;
  }

  parent.appendChild(element);

  return element;
}

function createInventoryRow(share, csrfToken) {
  const row = document.createElement('article');
  row.className = 'rounded-md border border-zinc-200 p-4 dark:border-zinc-800';
  row.setAttribute('data-external-share-row', share.uid);

  const heading = document.createElement('div');
  heading.className = 'flex flex-wrap items-start justify-between gap-3';
  const description = document.createElement('div');
  appendText(
    description,
    `${share.mode === 'one_time' ? 'One time' : 'Expires at'} · Active`,
    'text-sm font-medium text-zinc-950 dark:text-zinc-50',
  );
  appendText(description, share.uid, 'mt-1 font-mono text-xs text-zinc-500');
  heading.appendChild(description);

  const revokeForm = document.createElement('form');
  revokeForm.method = 'POST';
  revokeForm.action = share.revoke_url;
  const csrf = document.createElement('input');
  csrf.type = 'hidden';
  csrf.name = '_token';
  csrf.value = csrfToken;
  revokeForm.appendChild(csrf);
  const method = document.createElement('input');
  method.type = 'hidden';
  method.name = '_method';
  method.value = 'DELETE';
  revokeForm.appendChild(method);
  const revoke = document.createElement('button');
  revoke.className = 'af-secondary-button';
  revoke.type = 'submit';
  revoke.textContent = 'Revoke';
  revokeForm.appendChild(revoke);
  heading.appendChild(revokeForm);
  row.appendChild(heading);

  const details = document.createElement('p');
  details.className = 'mt-3 text-xs text-zinc-600 dark:text-zinc-400';
  details.textContent = `Created ${formattedDate(share.created_at)} by ${share.created_by}`;

  if (share.expires_at) {
    details.textContent += ` · Expires ${formattedDate(share.expires_at)}`;
  }

  row.appendChild(details);

  return row;
}

function validationMessage(payload) {
  if (
    !payload ||
    typeof payload !== 'object' ||
    !payload.errors ||
    typeof payload.errors !== 'object'
  ) {
    return 'The external link could not be created.';
  }

  const messages = Object.values(payload.errors)
    .flatMap((value) => (Array.isArray(value) ? value : []))
    .filter((value) => typeof value === 'string');

  return messages[0] ?? 'The external link could not be created.';
}

function localDateTimeValue(date) {
  const localTime = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

  return localTime.toISOString().slice(0, 16);
}

function syncExpiryMaximum(input) {
  const maximumHours = Number.parseInt(input.dataset.externalShareMaxExpiryHours ?? '', 10);

  if (!Number.isSafeInteger(maximumHours) || maximumHours < 1) {
    input.removeAttribute('max');

    return;
  }

  input.max = localDateTimeValue(new Date(Date.now() + maximumHours * 60 * 60 * 1000));
}

for (const root of document.querySelectorAll('[data-external-share-management]')) {
  const form = root.querySelector('[data-external-share-form]');

  if (!(form instanceof HTMLFormElement)) {
    continue;
  }

  const expiryField = form.querySelector('[data-external-share-expiry-field]');
  const expiryInput = form.querySelector('[data-external-share-expiry]');
  const status = form.querySelector('[data-external-share-form-status]');
  const secretPanel = root.querySelector('[data-external-share-secret]');
  const secretInput = root.querySelector('[data-external-share-secret-url]');
  const copyButton = root.querySelector('[data-copy-external-share-link]');
  const copyStatus = root.querySelector('[data-external-share-copy-status]');
  const inventory = root.querySelector('[data-external-share-inventory]');
  const activeCount = root.querySelector('[data-external-share-active-count]');
  const csrfInput = form.querySelector('input[name="_token"]');

  if (
    !(expiryField instanceof HTMLElement) ||
    !(expiryInput instanceof HTMLInputElement) ||
    !(status instanceof HTMLElement) ||
    !(secretPanel instanceof HTMLElement) ||
    !(secretInput instanceof HTMLInputElement) ||
    !(copyButton instanceof HTMLButtonElement) ||
    !(copyStatus instanceof HTMLElement) ||
    !(inventory instanceof HTMLElement) ||
    !(csrfInput instanceof HTMLInputElement)
  ) {
    continue;
  }

  const syncMode = () => {
    const selected = form.querySelector('input[name="mode"]:checked');
    const expiring = selected instanceof HTMLInputElement && selected.value === 'expires_at';
    expiryField.hidden = !expiring;
    expiryInput.required = expiring;

    if (expiring) {
      syncExpiryMaximum(expiryInput);
    } else {
      expiryInput.value = '';
    }
  };

  const clearIssuedSecret = () => {
    secretInput.value = '';
    secretPanel.hidden = true;
    copyStatus.textContent = '';
  };

  for (const mode of form.querySelectorAll('[data-external-share-mode]')) {
    mode.addEventListener('change', syncMode);
  }

  expiryInput.addEventListener('focus', () => syncExpiryMaximum(expiryInput));
  syncMode();
  root.setAttribute('data-external-share-management-ready', '');
  root.addEventListener('close', clearIssuedSecret);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = form.querySelector('button[type="submit"]');

    if (!(submit instanceof HTMLButtonElement)) {
      return;
    }

    submit.disabled = true;
    status.textContent = 'Creating external link…';
    clearIssuedSecret();

    try {
      const body = new FormData(form);
      const mode = body.get('mode');

      if (mode === 'expires_at') {
        const localExpiry = body.get('expires_at');

        if (typeof localExpiry === 'string' && localExpiry !== '') {
          const parsed = new Date(localExpiry);

          if (!Number.isNaN(parsed.getTime())) {
            body.set('expires_at', parsed.toISOString());
          }
        }
      } else {
        body.delete('expires_at');
      }

      const response = await fetch(form.action, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
        },
        body,
      });
      const payload = await response.json().catch(() => null);

      if (!response.ok || !payload || typeof payload.url !== 'string') {
        status.textContent = validationMessage(payload);

        return;
      }

      secretInput.value = payload.url;
      secretPanel.hidden = false;
      status.textContent = 'External link created. Copy it now.';
      const empty = inventory.querySelector('[data-external-share-empty]');
      empty?.remove();

      if (payload.share && typeof payload.share === 'object') {
        inventory.prepend(createInventoryRow(payload.share, csrfInput.value));
      }

      if (activeCount instanceof HTMLElement) {
        const current = Number.parseInt(activeCount.textContent ?? '0', 10);
        activeCount.textContent = `${Number.isNaN(current) ? 1 : current + 1} active`;
      }

      secretInput.focus();
      secretInput.select();
    } catch {
      status.textContent = 'The external link could not be created. Try again.';
    } finally {
      submit.disabled = false;
    }
  });

  copyButton.addEventListener('click', async () => {
    if (secretInput.value === '') {
      return;
    }

    copyButton.disabled = true;

    try {
      const copied = await copyText(secretInput.value);
      copyStatus.textContent = copied
        ? 'External link copied.'
        : 'Copy failed. Select and copy the link manually.';
    } finally {
      copyButton.disabled = false;
    }
  });
}
