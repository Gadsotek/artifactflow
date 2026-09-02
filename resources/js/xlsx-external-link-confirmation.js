const externalLinkRequest = 'artifactflow:xlsx-external-link-request';
const initialisedFrames = new WeakSet();

export function safeConfirmedExternalTarget(target) {
  if (
    typeof target !== 'string' ||
    Array.from(target).some((character) => {
      const codePoint = character.codePointAt(0);

      return codePoint !== undefined && (codePoint <= 31 || codePoint === 127);
    })
  ) {
    return null;
  }

  try {
    const url = new URL(target);

    if (
      !['http:', 'https:', 'mailto:'].includes(url.protocol) ||
      url.username !== '' ||
      url.password !== ''
    ) {
      return null;
    }

    return url.href;
  } catch {
    return null;
  }
}

function exactLinkRequest(payload) {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    !Array.isArray(payload) &&
    Object.keys(payload).length === 2 &&
    Object.keys(payload).every((key) => ['target', 'type'].includes(key)) &&
    payload.type === externalLinkRequest
  );
}

function createConfirmationDialog() {
  const dialog = document.createElement('dialog');
  dialog.className = 'artifactflow-editor-dialog af-compact-dialog';
  dialog.setAttribute('aria-labelledby', 'xlsx-external-link-dialog-title');
  dialog.dataset.xlsxExternalLinkDialog = '';

  const header = document.createElement('div');
  header.className = 'af-dialog-header';
  const headingGroup = document.createElement('div');
  const eyebrow = document.createElement('p');
  eyebrow.className = 'af-eyebrow';
  eyebrow.textContent = 'External workbook link';
  const heading = document.createElement('h2');
  heading.id = 'xlsx-external-link-dialog-title';
  heading.textContent = 'Open external workbook link?';
  headingGroup.append(eyebrow, heading);
  header.append(headingGroup);

  const body = document.createElement('div');
  body.className = 'af-dialog-scroll space-y-4';
  const warning = document.createElement('p');
  warning.textContent =
    'This destination came from an untrusted workbook. Check it before opening a new browser tab.';
  const destination = document.createElement('code');
  destination.className =
    'block break-all rounded-md border border-zinc-300 p-3 text-sm dark:border-zinc-700';
  destination.dataset.xlsxExternalLinkTarget = '';
  const actions = document.createElement('div');
  actions.className = 'flex flex-wrap justify-end gap-2';
  const cancel = document.createElement('button');
  cancel.className = 'af-secondary-button';
  cancel.type = 'button';
  cancel.textContent = 'Cancel';
  const open = document.createElement('a');
  open.className = 'af-primary-button';
  open.target = '_blank';
  open.rel = 'noopener noreferrer';
  open.referrerPolicy = 'no-referrer';
  open.textContent = 'Open external link';
  actions.append(cancel, open);
  body.append(warning, destination, actions);
  dialog.append(header, body);

  const clear = () => {
    destination.textContent = '';
    open.removeAttribute('href');
  };
  cancel.addEventListener('click', () => dialog.close());
  open.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', clear);
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });
  document.body.append(dialog);

  return { destination, dialog, open };
}

export function initialiseXlsxExternalLinkConfirmation(root = document) {
  const frames = root.querySelectorAll('[data-xlsx-preview] [data-artifact-preview-frame]');

  for (const frame of frames) {
    if (!(frame instanceof HTMLIFrameElement) || initialisedFrames.has(frame)) {
      continue;
    }

    initialisedFrames.add(frame);
    const confirmation = createConfirmationDialog();

    window.addEventListener('message', (event) => {
      if (
        event.origin !== 'null' ||
        event.source !== frame.contentWindow ||
        !exactLinkRequest(event.data)
      ) {
        return;
      }

      const target = safeConfirmedExternalTarget(event.data.target);

      if (target === null) {
        return;
      }

      confirmation.destination.textContent = target;
      confirmation.open.href = target;
      confirmation.dialog.showModal();
    });
  }
}
