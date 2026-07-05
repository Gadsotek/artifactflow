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

function submitDraftToSandbox(frame, endpoint, content) {
  const submission = document.createElement('form');
  submission.method = 'POST';
  submission.action = endpoint;
  submission.target = frame.getAttribute('name') ?? '';
  submission.hidden = true;
  // Multipart keeps request overhead close to the real HTML byte count. That
  // lets Caddy enforce the route-specific edge limit before PHP parses the body;
  // URL encoding can expand arbitrary HTML to roughly three times its size.
  submission.enctype = 'multipart/form-data';

  const field = document.createElement('input');
  field.type = 'hidden';
  field.name = 'content';
  field.value = content;
  submission.append(field);

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
  const endpoint = form.getAttribute('data-html-draft-preview-endpoint') ?? '';

  if (
    !(panel instanceof HTMLElement) ||
    !(button instanceof HTMLButtonElement) ||
    !(frame instanceof HTMLIFrameElement) ||
    !(status instanceof HTMLElement) ||
    !(textarea instanceof HTMLTextAreaElement) ||
    !(type instanceof HTMLSelectElement) ||
    !(mode instanceof HTMLSelectElement)
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
    if (endpoint === '' || frameName === '') {
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

      submitDraftToSandbox(frame, endpoint, content);
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
