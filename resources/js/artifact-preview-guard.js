// Canonical artifact-preview guard body. Single source of truth shared by the
// served-artifact path (app/Application/PageCatalog/ArtifactPreviewDocumentGuard.php
// reads this file at runtime) and the pre-save draft preview
// (resources/js/html-draft-preview.js imports it with ?raw). Edit here only;
// both paths wrap this IIFE in their own <script> tag.
(() => {
  const guardScript = document.currentScript;
  const recoveryTarget = guardScript?.hasAttribute('data-artifactflow-preview-recovery')
    ? window.parent
    : null;
  const noop = () => {};
  const reportPreviewReady = () => {
    try {
      recoveryTarget?.postMessage('artifactflow:preview-ready', '*');
    } catch {
      // The parent browsing context may already be gone.
    }
  };
  const disabled = () => new TypeError('Artifact browser capability is disabled.');
  const rejectDisabled = () => Promise.reject(disabled());
  const defineValue = (target, key, value) => {
    try {
      Object.defineProperty(target, key, {
        configurable: false,
        enumerable: false,
        value,
        writable: false,
      });
    } catch {
      try {
        target[key] = value;
      } catch {
        // The sandbox or browser owns this property already.
      }
    }
  };
  const defineGetter = (target, key, value) => {
    try {
      Object.defineProperty(target, key, {
        configurable: false,
        enumerable: false,
        get: () => value,
      });
    } catch {
      // The sandbox or browser owns this property already.
    }
  };
  const blockedConstructor = function blockedConstructor() {
    throw disabled();
  };
  const consoleStub = Object.freeze({
    assert: noop,
    clear: noop,
    count: noop,
    countReset: noop,
    debug: noop,
    dir: noop,
    dirxml: noop,
    error: noop,
    group: noop,
    groupCollapsed: noop,
    groupEnd: noop,
    info: noop,
    log: noop,
    profile: noop,
    profileEnd: noop,
    table: noop,
    time: noop,
    timeEnd: noop,
    timeLog: noop,
    timeStamp: noop,
    trace: noop,
    warn: noop,
  });
  const storageStub = Object.freeze({
    get length() {
      return 0;
    },
    clear: noop,
    getItem: () => null,
    key: () => null,
    removeItem: noop,
    setItem: noop,
  });
  const cookieDescriptor = {
    configurable: false,
    enumerable: true,
    get: () => '',
    set: noop,
  };
  const blockedWindowProxy = Object.freeze({
    blur: noop,
    close: noop,
    focus: noop,
    postMessage: noop,
  });
  const blockNavigationEvent = (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    return false;
  };
  const removeRefreshMetaTags = () => {
    try {
      for (const meta of document.querySelectorAll('meta[http-equiv]')) {
        if (meta.getAttribute('http-equiv')?.trim().toLowerCase() === 'refresh') {
          meta.remove();
        }
      }
    } catch {
      // The document may not be queryable yet.
    }
  };
  const removeResourceHintLinks = () => {
    try {
      for (const link of document.querySelectorAll('link[rel]')) {
        const rels = (link.getAttribute('rel') ?? '').trim().toLowerCase().split(/\s+/u);

        if (
          rels.some(
            (rel) =>
              rel === 'dns-prefetch' ||
              rel === 'preconnect' ||
              rel === 'prefetch' ||
              rel === 'prerender',
          )
        ) {
          link.remove();
        }
      }
    } catch {
      // The document may not be queryable yet.
    }
  };
  const watchDocument = () => {
    removeRefreshMetaTags();
    removeResourceHintLinks();

    try {
      new MutationObserver(() => {
        removeRefreshMetaTags();
        removeResourceHintLinks();
      }).observe(document.documentElement, {
        attributeFilter: ['content', 'http-equiv', 'rel', 'href'],
        attributes: true,
        childList: true,
        subtree: true,
      });
    } catch {
      // The sandbox or browser owns document observation already.
    }
  };

  defineValue(window, 'console', consoleStub);
  defineValue(window, 'fetch', rejectDisabled);
  defineValue(window, 'XMLHttpRequest', blockedConstructor);
  defineValue(window, 'WebSocket', blockedConstructor);
  defineValue(window, 'EventSource', blockedConstructor);
  defineValue(window, 'WebTransport', blockedConstructor);
  defineValue(window, 'RTCPeerConnection', blockedConstructor);
  defineValue(window, 'webkitRTCPeerConnection', blockedConstructor);
  defineValue(window, 'RTCDataChannel', blockedConstructor);
  defineValue(window, 'RTCIceTransport', blockedConstructor);
  defineValue(window, 'RTCDtlsTransport', blockedConstructor);
  defineValue(window, 'RTCPeerConnectionIceEvent', blockedConstructor);
  defineValue(window, 'Worker', blockedConstructor);
  defineValue(window, 'SharedWorker', blockedConstructor);
  defineValue(window, 'BroadcastChannel', blockedConstructor);
  defineValue(window, 'open', () => null);
  defineValue(window, 'postMessage', noop);
  defineValue(window, 'print', noop);
  defineGetter(window, 'caches', undefined);
  defineGetter(window, 'indexedDB', undefined);
  defineGetter(window, 'localStorage', storageStub);
  defineGetter(window, 'sessionStorage', storageStub);
  defineGetter(navigator, 'serviceWorker', undefined);
  defineValue(navigator, 'sendBeacon', () => false);

  try {
    Object.defineProperty(Document.prototype, 'cookie', cookieDescriptor);
  } catch {
    // The sandbox or browser owns this property already.
  }
  try {
    Object.defineProperty(document, 'cookie', cookieDescriptor);
  } catch {
    // The sandbox or browser owns this property already.
  }
  defineValue(window, 'parent', blockedWindowProxy);
  defineValue(window, 'top', blockedWindowProxy);
  defineValue(window, 'opener', null);
  document.addEventListener(
    'click',
    (event) => {
      const target = event.target;

      if (target instanceof Element && target.closest('a, area')) {
        blockNavigationEvent(event);
      }
    },
    true,
  );
  document.addEventListener(
    'auxclick',
    (event) => {
      const target = event.target;

      if (target instanceof Element && target.closest('a, area')) {
        blockNavigationEvent(event);
      }
    },
    true,
  );
  document.addEventListener('submit', blockNavigationEvent, true);
  // Best-effort neutralization of programmatic self-navigation, layered on the real
  // boundary (opaque-origin sandbox + the app's frame-src CSP, which pins the frame to
  // the artifact origin). This matches how every other escape vector is stubbed. Note
  // `window.location` itself is non-configurable, so `location.href = ...` cannot be
  // intercepted here; assign()/replace() are the interceptable cross-origin nav methods,
  // and unload is cancelled. A script still cannot reach app storage or the network.
  defineValue(window.location, 'assign', noop);
  defineValue(window.location, 'replace', noop);

  if (recoveryTarget !== null) {
    window.addEventListener('load', reportPreviewReady, { capture: true, once: true });
  }
  window.addEventListener(
    'beforeunload',
    (event) => {
      event.preventDefault();
      event.returnValue = '';
    },
    true,
  );
  watchDocument();
  try {
    guardScript?.remove();
  } catch {
    // Removing the setup marker is best-effort hardening only.
  }
})();
