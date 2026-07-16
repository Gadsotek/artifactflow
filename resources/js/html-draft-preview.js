// The draft preview renders on the isolated artifact origin, exactly like a
// saved artifact: we POST the unsaved HTML into the sandbox iframe (form target)
// so the browser loads it as a document on the artifact host with that origin's
// hardened CSP + guard. It deliberately does NOT use `srcdoc`, which would run on
// the app origin and inherit the app CSP (`style-src 'self' 'nonce-…'`, no
// unsafe-inline), silently dropping the artifact's inline styles. Regression
// pinned in tests/e2e/editor.spec.ts.

async function previewContent(form, mode, textarea) {
  if (mode.value !== 'html_upload') {
    return textarea.value;
  }

  const fileInput = form.querySelector('input[name="html_file"]');

  if (!(fileInput instanceof HTMLInputElement) || !(fileInput.files?.[0] instanceof File)) {
    throw new Error('Choose an HTML file before previewing.');
  }

  return fileInput.files[0].text();
}

async function contentClaims(content) {
  if (typeof window.crypto?.subtle?.digest !== 'function') {
    throw new Error('Secure draft preview signing is unavailable in this browser.');
  }

  const bytes = new TextEncoder().encode(content);
  const digest = await window.crypto.subtle.digest('SHA-256', bytes);
  const sha256 = Array.from(new Uint8Array(digest), (value) =>
    value.toString(16).padStart(2, '0'),
  ).join('');

  return { bytes: bytes.byteLength, sha256 };
}

async function issueCapability(form, endpoint, workspaceUid, content) {
  const csrfInput = form.querySelector('input[name="_token"]');

  if (!(csrfInput instanceof HTMLInputElement) || csrfInput.value === '') {
    throw new Error('Draft preview security token is unavailable. Reload the page and try again.');
  }

  const claims = await contentClaims(content);
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfInput.value,
    },
    body: JSON.stringify({
      workspace_uid: workspaceUid,
      content_bytes: claims.bytes,
      content_sha256: claims.sha256,
    }),
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const validationMessage = Object.values(payload?.errors ?? {})
      .flat()
      .find((message) => typeof message === 'string');

    if (typeof validationMessage === 'string') {
      throw new Error(validationMessage);
    }

    if (response.status === 401 || response.status === 419) {
      throw new Error('Your session expired. Reload the page and try again.');
    }

    if (response.status === 403) {
      throw new Error('You cannot preview HTML in the selected workspace.');
    }

    throw new Error('Draft preview authorization failed.');
  }

  if (typeof payload?.capability !== 'string' || payload.capability === '') {
    throw new Error('Draft preview authorization returned an invalid response.');
  }

  return payload.capability;
}

function submitDraftToSandbox(frame, endpoint, capability, content) {
  const submission = document.createElement('form');
  submission.method = 'POST';
  submission.action = endpoint;
  submission.target = frame.getAttribute('name') ?? '';
  submission.hidden = true;
  // Multipart keeps request overhead close to the real HTML byte count. That
  // lets Caddy enforce the route-specific edge limit before PHP parses the body;
  // URL encoding can expand arbitrary HTML to roughly three times its size.
  submission.enctype = 'multipart/form-data';

  const capabilityField = document.createElement('input');
  capabilityField.type = 'hidden';
  capabilityField.name = 'capability';
  capabilityField.value = capability;
  submission.append(capabilityField);

  // Text controls are newline-normalized during form submission. Send the
  // draft as an in-memory file so multipart preserves the exact UTF-8 bytes
  // whose length and SHA-256 the app origin signed.
  const transfer = new DataTransfer();
  transfer.items.add(new File([content], 'artifactflow-draft.html', { type: 'text/html' }));
  const contentField = document.createElement('input');
  contentField.type = 'file';
  contentField.name = 'content';
  contentField.hidden = true;
  contentField.files = transfer.files;
  submission.append(contentField);

  document.body.append(submission);

  // The form must stay attached until the iframe navigation it initiates has
  // committed; removing it synchronously after submit() cancels the load in
  // Chromium. Clean up once the frame has loaded the response instead.
  frame.addEventListener('load', () => submission.remove(), { once: true });
  submission.submit();
}

function initialiseHtmlDraftPreview(form) {
  const panel = form.querySelector('[data-html-draft-preview]');
  const button = form.querySelector('[data-html-draft-preview-button]');
  const frame = form.querySelector('[data-html-draft-preview-frame]');
  const status = form.querySelector('[data-html-draft-preview-status]');
  const textarea = form.querySelector('[data-editor-textarea]');
  const type = form.querySelector('select[name="type"]');
  const mode = form.querySelector('select[name="mode"]');
  const workspace = form.querySelector('select[name="workspace_uid"]');
  const endpoint = form.getAttribute('data-html-draft-preview-endpoint') ?? '';
  const capabilityEndpoint = form.getAttribute('data-html-draft-preview-capability-endpoint') ?? '';

  if (
    !(panel instanceof HTMLElement) ||
    !(button instanceof HTMLButtonElement) ||
    !(frame instanceof HTMLIFrameElement) ||
    !(status instanceof HTMLElement) ||
    !(textarea instanceof HTMLTextAreaElement) ||
    !(type instanceof HTMLSelectElement) ||
    !(mode instanceof HTMLSelectElement) ||
    !(workspace instanceof HTMLSelectElement)
  ) {
    return;
  }

  const frameName = frame.getAttribute('name') ?? '';

  const updateVisibility = () => {
    panel.hidden =
      type.value !== 'html_artifact' ||
      (mode.value !== 'html_paste' && mode.value !== 'html_upload');
  };

  type.addEventListener('change', () => {
    if (type.value === 'html_artifact' && mode.value === 'markdown') {
      mode.value = 'html_paste';
    } else if (type.value === 'markdown') {
      mode.value = 'markdown';
    }

    updateVisibility();
  });
  mode.addEventListener('change', () => {
    const nextType = mode.value === 'markdown' ? 'markdown' : 'html_artifact';

    if (type.value !== nextType) {
      type.value = nextType;
      type.dispatchEvent(new Event('change'));
    }

    updateVisibility();
  });

  button.addEventListener('click', async () => {
    if (endpoint === '' || capabilityEndpoint === '' || frameName === '') {
      status.textContent = 'Draft preview is unavailable.';

      return;
    }

    button.disabled = true;
    status.textContent = 'Preparing isolated preview…';

    try {
      const content = await previewContent(form, mode, textarea);

      if (content.trim() === '') {
        throw new Error('Add HTML content before previewing.');
      }

      const capability = await issueCapability(form, capabilityEndpoint, workspace.value, content);
      submitDraftToSandbox(frame, endpoint, capability, content);
      status.textContent = 'Draft preview running in the isolated sandbox.';
    } catch (error) {
      status.textContent =
        error instanceof Error ? error.message : 'Draft preview could not be prepared.';
    } finally {
      button.disabled = false;
    }
  });

  updateVisibility();
}

for (const form of document.querySelectorAll('[data-html-draft-preview-form]')) {
  initialiseHtmlDraftPreview(form);
}
