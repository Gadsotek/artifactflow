const previewReady = 'artifactflow:preview-ready';
const readySignalGracePeriodMs = 100;
// A saved artifact runs untrusted script and can self-navigate its own frame in a
// loop. Each child load that arrives without the ready signal mints one fresh
// signed URL, and every renewal hits the authenticated app endpoint — so an
// unbounded loop lets the artifact drain the *viewer's own* rate-limit budget
// (self-inflicted, no boundary crossed). Throttle renewals to a minimum interval:
// a genuine expiry needs recovering roughly once per URL TTL, far slower than this
// floor, so a real recovery always fires immediately and is never dropped — a
// renewal requested too soon is deferred to the end of the interval, not
// discarded — while a hostile loop is bounded to one renewal per interval.
const minRecoveryIntervalMs = 5_000;

function initialiseArtifactPreviewRefresh(wrapper) {
  const frame = wrapper.querySelector('[data-artifact-preview-frame]');
  const endpoint = wrapper.getAttribute('data-artifact-preview-refresh-endpoint') ?? '';

  if (!(frame instanceof HTMLIFrameElement) || endpoint === '') {
    return;
  }

  let readySignalPending = false;
  let refreshInFlight = false;
  let awaitingRecoveryLoad = false;
  let recoveryTimer = 0;
  let lastRecoveryAt = 0;
  let deferredRecoveryTimer = 0;

  window.addEventListener('message', (event) => {
    if (
      event.origin !== 'null' ||
      event.source !== frame.contentWindow ||
      event.data !== previewReady
    ) {
      return;
    }

    readySignalPending = true;
  });

  const recoverExpiredPreview = async () => {
    if (refreshInFlight) {
      return;
    }

    refreshInFlight = true;
    lastRecoveryAt = Date.now();

    try {
      const response = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        return;
      }

      const payload = await response.json();

      if (typeof payload.url !== 'string' || payload.url === '') {
        return;
      }

      const currentUrl = new URL(frame.src);
      const freshUrl = new URL(payload.url);

      if (freshUrl.origin !== currentUrl.origin || freshUrl.pathname !== currentUrl.pathname) {
        return;
      }

      awaitingRecoveryLoad = true;
      frame.src = freshUrl.toString();
    } catch {
      // The app page may be navigating or the authenticated session may have ended.
    } finally {
      refreshInFlight = false;
    }
  };

  // Rate-limit renewals without ever dropping a legitimate one: a request within
  // the interval of the last renewal is coalesced into a single deferred attempt
  // at the end of the interval, so a self-navigating artifact cannot exceed one
  // renewal per interval while a real expiry still recovers.
  const requestRecovery = () => {
    if (deferredRecoveryTimer !== 0) {
      return;
    }

    const sinceLastRecovery = Date.now() - lastRecoveryAt;

    if (sinceLastRecovery >= minRecoveryIntervalMs) {
      void recoverExpiredPreview();

      return;
    }

    deferredRecoveryTimer = window.setTimeout(() => {
      deferredRecoveryTimer = 0;
      void recoverExpiredPreview();
    }, minRecoveryIntervalMs - sinceLastRecovery);
  };

  frame.addEventListener('load', () => {
    window.clearTimeout(recoveryTimer);
    recoveryTimer = window.setTimeout(() => {
      if (readySignalPending) {
        readySignalPending = false;
        awaitingRecoveryLoad = false;

        return;
      }

      // A failed recovery response must not create an iframe reload loop. One
      // authenticated renewal is attempted for each child-initiated load.
      if (awaitingRecoveryLoad) {
        awaitingRecoveryLoad = false;

        return;
      }

      requestRecovery();
    }, readySignalGracePeriodMs);
  });
}

for (const wrapper of document.querySelectorAll('[data-artifact-preview-refresh-endpoint]')) {
  initialiseArtifactPreviewRefresh(wrapper);
}
