import './external-share-theme';
import { storeExternalShareWindowToken } from './external-share-window';

const root = document.querySelector('[data-external-share-bootstrap]');
const status = document.querySelector('[data-external-share-bootstrap-status]');

function unavailable() {
  document.title = 'External artifact unavailable · ArtifactFlow';

  if (status instanceof HTMLElement) {
    status.textContent =
      'This external artifact is unavailable. Ask the person who shared it with you for a new link.';
  }
}

function safeViewerUrl(value, selector) {
  if (typeof value !== 'string') {
    return null;
  }

  try {
    const url = new URL(value, window.location.origin);
    const segments = url.pathname.split('/');
    const hasExpectedPath =
      segments.length === 6 &&
      segments[1] === 'external-shares' &&
      segments[2] === selector &&
      segments[3] === 'sessions' &&
      /^[0-9A-Za-z]{26}$/u.test(segments[4] ?? '') &&
      segments[5] === 'viewer';

    return url.origin === window.location.origin && hasExpectedPath ? url.toString() : null;
  } catch {
    return null;
  }
}

function enterViewer(payload, selector) {
  const viewerUrl = safeViewerUrl(payload.viewer_url, selector);
  const windowToken = payload.window_token;

  if (
    viewerUrl === null ||
    typeof windowToken !== 'string' ||
    !storeExternalShareWindowToken(selector, windowToken)
  ) {
    unavailable();

    return;
  }

  payload.window_token = '';
  window.location.replace(viewerUrl);
}

function replaceWithConfirmation(payload, selector) {
  if (
    !(root instanceof HTMLElement) ||
    !payload.artifact ||
    typeof payload.artifact !== 'object' ||
    typeof payload.artifact.title !== 'string' ||
    typeof payload.artifact.mode !== 'string' ||
    typeof payload.csrf_token !== 'string'
  ) {
    unavailable();

    return;
  }

  const section = document.createElement('section');
  section.className = 'af-external-card';
  const brand = document.createElement('p');
  brand.className = 'af-eyebrow';
  brand.textContent = 'ArtifactFlow external artifact';
  section.appendChild(brand);
  const heading = document.createElement('h1');
  heading.textContent = payload.artifact.title;
  section.appendChild(heading);
  const live = document.createElement('p');
  live.className = 'af-external-card-meta';
  live.textContent = 'Live artifact · latest version';
  section.appendChild(live);

  if (payload.artifact.mode === 'expires_at' && typeof payload.artifact.expires_at === 'string') {
    const expiry = document.createElement('p');
    expiry.className = 'af-external-card-meta';
    const expiryDate = new Date(payload.artifact.expires_at);
    expiry.textContent = Number.isNaN(expiryDate.getTime())
      ? 'This link expires at a fixed time.'
      : `Expires ${new Intl.DateTimeFormat(undefined, {
          dateStyle: 'medium',
          timeStyle: 'short',
        }).format(expiryDate)}`;
    section.appendChild(expiry);
  }

  const explanation = document.createElement('div');
  explanation.className = 'af-external-warning';

  if (payload.artifact.acknowledgement_required === true) {
    explanation.textContent =
      'This is a live artifact shared through ArtifactFlow. It may change, and you will see its latest version. Artifacts can contain misleading or harmful content. Never enter passwords, payment details, API keys, recovery codes, or other confidential information. Continue only if you trust the sender and expected this link.';
  } else {
    explanation.textContent =
      'This link can be opened once. Opening it consumes the link and keeps the artifact available in this browser window.';
  }

  section.appendChild(explanation);
  const actions = document.createElement('div');
  actions.className = 'mt-6 flex flex-wrap gap-3';
  const open = document.createElement('button');
  open.className = 'af-primary-button';
  open.type = 'button';
  open.textContent =
    payload.artifact.acknowledgement_required === true
      ? 'I understand — open artifact'
      : 'Open artifact';
  actions.appendChild(open);
  const leave = document.createElement('button');
  leave.className = 'af-secondary-button';
  leave.type = 'button';
  leave.textContent = 'Leave';
  leave.addEventListener('click', () => window.location.replace('about:blank'));
  actions.appendChild(leave);
  section.appendChild(actions);
  const actionStatus = document.createElement('p');
  actionStatus.className = 'af-external-action-status';
  actionStatus.setAttribute('role', 'status');
  actionStatus.setAttribute('aria-live', 'polite');
  section.appendChild(actionStatus);

  root.replaceChildren(section);
  open.focus();
  open.addEventListener('click', async () => {
    open.disabled = true;
    actionStatus.textContent = 'Opening artifact…';

    try {
      const response = await fetch(`/external-shares/${encodeURIComponent(selector)}/open`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ csrf_token: payload.csrf_token }),
      });
      const opened = await response.json().catch(() => null);

      if (!response.ok || opened?.state !== 'viewer') {
        unavailable();

        return;
      }

      enterViewer(opened, selector);
    } catch {
      unavailable();
    } finally {
      open.disabled = false;
    }
  });
}

async function bootstrap() {
  if (!(root instanceof HTMLElement) || !(status instanceof HTMLElement)) {
    return;
  }

  const selector = root.getAttribute('data-external-share-uid') ?? '';
  let secret = '';
  const match = window.location.hash.match(/^#secret=([A-Za-z0-9_-]{43})$/u);

  if (match) {
    secret = match[1];
  }

  history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);

  if (!/^[0-9A-Za-z]{26}$/u.test(selector) || secret === '') {
    unavailable();

    return;
  }

  try {
    const response = await fetch(`/external-shares/${encodeURIComponent(selector)}/exchange`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ secret }),
    });
    secret = '';
    const payload = await response.json().catch(() => null);

    if (!response.ok || !payload || typeof payload !== 'object') {
      unavailable();

      return;
    }

    if (payload.state === 'viewer') {
      enterViewer(payload, selector);

      return;
    }

    if (payload.state === 'confirmation') {
      replaceWithConfirmation(payload, selector);

      return;
    }

    unavailable();
  } catch {
    unavailable();
  }
}

void bootstrap();
