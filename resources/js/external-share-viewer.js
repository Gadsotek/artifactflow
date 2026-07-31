import './external-share-theme';
import { externalShareWindowToken } from './external-share-window';

function unavailable(root) {
  document.title = 'External artifact unavailable · ArtifactFlow';
  const section = document.createElement('section');
  section.className = 'af-external-card';
  const eyebrow = document.createElement('p');
  eyebrow.className = 'af-eyebrow';
  eyebrow.textContent = 'ArtifactFlow';
  section.appendChild(eyebrow);
  const heading = document.createElement('h1');
  heading.textContent = 'This external artifact is unavailable.';
  section.appendChild(heading);
  const explanation = document.createElement('p');
  explanation.textContent = 'Ask the person who shared it with you for a new link.';
  section.appendChild(explanation);
  root.className = 'af-external-gate';
  root.replaceChildren(section);
}

function renderLocalTimes(root) {
  for (const time of root.querySelectorAll('[data-external-share-local-time]')) {
    if (!(time instanceof HTMLTimeElement)) {
      continue;
    }

    const date = new Date(time.dateTime);

    if (Number.isNaN(date.getTime())) {
      continue;
    }

    const format = time.dataset.externalShareLocalTime;
    const options =
      format === 'time'
        ? { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }
        : { dateStyle: 'medium', timeStyle: 'short' };
    time.textContent = new Intl.DateTimeFormat(undefined, options).format(date);
  }
}

async function initialiseContent(root) {
  renderLocalTimes(root);
  const heading = root.querySelector('[data-external-share-title]');

  if (heading instanceof HTMLElement) {
    document.title = `${heading.textContent ?? 'External artifact'} · ArtifactFlow`;
  }

  if (root.querySelector('[data-mermaid-diagram]')) {
    await import('./mermaid-renderer').then(({ renderMermaidDiagrams }) =>
      renderMermaidDiagrams(root),
    );
  }

  if (root.querySelector('[data-artifact-preview]')) {
    await import('./artifact-fullscreen');
  }

  if (root.querySelector('[data-artifact-preview-refresh-endpoint]')) {
    await import('./artifact-preview-refresh');
  }
}

async function loadViewer() {
  const root = document.querySelector('[data-external-share-viewer-shell]');

  if (!(root instanceof HTMLElement)) {
    return;
  }

  const selector = root.dataset.externalShareUid ?? '';
  const endpoint = root.dataset.externalShareContentEndpoint ?? '';
  const windowToken = externalShareWindowToken(selector);

  if (windowToken === null || endpoint === '') {
    unavailable(root);

    return;
  }

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'text/html',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ window_token: windowToken }),
    });

    if (!response.ok) {
      unavailable(root);

      return;
    }

    root.className = 'af-external-viewer-root';
    root.innerHTML = await response.text();
    await initialiseContent(root);
  } catch {
    unavailable(root);
  }
}

void loadViewer();
