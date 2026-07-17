// Canonical artifact-preview guard body. The shared sandbox responder injects
// this source into both saved artifacts and pre-save draft previews through
// ArtifactPreviewDocumentGuard. Edit here only.
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
  const nestedBrowsingContextTags = new Set(['iframe', 'frame', 'fencedframe', 'portal']);
  const nativeCreateElement = Document.prototype.createElement;
  const nativeCreateElementNS = Document.prototype.createElementNS;
  const nativeDocumentQuerySelectorAll = Document.prototype.querySelectorAll;
  const nativeElementQuerySelectorAll = Element.prototype.querySelectorAll;
  const nativeFragmentQuerySelectorAll = DocumentFragment.prototype.querySelectorAll;
  const nativeGetAttribute = Element.prototype.getAttribute;
  const nativeRemoveChild = Node.prototype.removeChild;
  const nativeInnerHTMLDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
  const nativeInnerHTMLGetter = nativeInnerHTMLDescriptor?.get;
  const nativeInnerHTMLSetter = nativeInnerHTMLDescriptor?.set;
  const markupString = (markup) => (markup === null ? '' : `${markup}`);
  const localTagName = (name) => {
    const normalized = `${name}`.trim().toLowerCase();
    const separator = normalized.lastIndexOf(':');

    return separator === -1 ? normalized : normalized.slice(separator + 1);
  };
  const isNestedBrowsingContextTag = (name) => nestedBrowsingContextTags.has(localTagName(name));
  const isNestedBrowsingContextElement = (node) =>
    node instanceof Element && nestedBrowsingContextTags.has(node.localName.toLowerCase());
  const inertElement = (ownerDocument) => {
    const element = nativeCreateElement.call(ownerDocument, 'span');
    element.setAttribute('data-artifactflow-blocked-browsing-context', '');

    return element;
  };
  const elementsBelow = (root) => {
    if (root instanceof Document) {
      return nativeDocumentQuerySelectorAll.call(root, '*');
    }

    if (root instanceof DocumentFragment) {
      return nativeFragmentQuerySelectorAll.call(root, '*');
    }

    if (root instanceof Element) {
      return nativeElementQuerySelectorAll.call(root, '*');
    }

    return [];
  };
  const removeElement = (element) => {
    const parent = element.parentNode;

    if (parent !== null) {
      nativeRemoveChild.call(parent, element);
    }
  };
  const removeNestedBrowsingContexts = (root) => {
    if (isNestedBrowsingContextElement(root)) {
      removeElement(root);

      return;
    }

    for (const element of elementsBelow(root)) {
      if (isNestedBrowsingContextElement(element)) {
        removeElement(element);
      }
    }
  };
  const insertionOwnerDocument = (target) =>
    target instanceof Document ? target : (target?.ownerDocument ?? document);
  const sanitizeInsertionNode = (node, ownerDocument) => {
    if (!(node instanceof Node)) {
      return node;
    }

    if (isNestedBrowsingContextElement(node)) {
      return inertElement(ownerDocument);
    }

    removeNestedBrowsingContexts(node);

    return node;
  };
  const sanitizeMarkup = (markup, ownerDocument = document) => {
    const source = markupString(markup);

    if (
      typeof nativeInnerHTMLGetter !== 'function' ||
      typeof nativeInnerHTMLSetter !== 'function'
    ) {
      return source;
    }

    const template = nativeCreateElement.call(ownerDocument, 'template');
    nativeInnerHTMLSetter.call(template, source);
    removeNestedBrowsingContexts(template.content);

    return nativeInnerHTMLGetter.call(template);
  };
  const hardenMarkupMethod = (target, key, argumentIndex = 0) => {
    const original = target?.[key];

    if (typeof original !== 'function') {
      return;
    }

    defineValue(target, key, function hardenedMarkupMethod(...args) {
      const ownerDocument = this?.ownerDocument ?? document;
      args[argumentIndex] = sanitizeMarkup(args[argumentIndex], ownerDocument);

      return Reflect.apply(original, this, args);
    });
  };
  const hardenMarkupSetter = (target, key) => {
    try {
      const descriptor = Object.getOwnPropertyDescriptor(target, key);

      if (typeof descriptor?.set !== 'function') {
        return;
      }

      Object.defineProperty(target, key, {
        configurable: false,
        enumerable: descriptor.enumerable,
        get: descriptor.get,
        set(value) {
          descriptor.set.call(this, sanitizeMarkup(value, this.ownerDocument ?? document));
        },
      });
    } catch {
      // The browser owns this markup sink already.
    }
  };
  const hardenNodeMethod = (target, key, argumentIndexes = null) => {
    const original = target?.[key];

    if (typeof original !== 'function') {
      return;
    }

    defineValue(target, key, function hardenedNodeMethod(...args) {
      const ownerDocument = insertionOwnerDocument(this);
      const indexes = argumentIndexes ?? args.map((_, index) => index);

      for (const index of indexes) {
        args[index] = sanitizeInsertionNode(args[index], ownerDocument);
      }

      return Reflect.apply(original, this, args);
    });
  };
  const hardenNodeInsertionSinks = () => {
    hardenNodeMethod(Node.prototype, 'appendChild', [0]);
    hardenNodeMethod(Node.prototype, 'insertBefore', [0]);
    hardenNodeMethod(Node.prototype, 'replaceChild', [0]);
    hardenNodeMethod(Element.prototype, 'append');
    hardenNodeMethod(Element.prototype, 'prepend');
    hardenNodeMethod(Element.prototype, 'replaceChildren');
    hardenNodeMethod(Element.prototype, 'before');
    hardenNodeMethod(Element.prototype, 'after');
    hardenNodeMethod(Element.prototype, 'replaceWith');
    hardenNodeMethod(Element.prototype, 'insertAdjacentElement', [1]);
    hardenNodeMethod(Document.prototype, 'append');
    hardenNodeMethod(Document.prototype, 'prepend');
    hardenNodeMethod(Document.prototype, 'replaceChildren');
    hardenNodeMethod(DocumentFragment.prototype, 'append');
    hardenNodeMethod(DocumentFragment.prototype, 'prepend');
    hardenNodeMethod(DocumentFragment.prototype, 'replaceChildren');
    hardenNodeMethod(Range.prototype, 'insertNode', [0]);
    hardenNodeMethod(Range.prototype, 'surroundContents', [0]);

    if (typeof CharacterData === 'function') {
      hardenNodeMethod(CharacterData.prototype, 'before');
      hardenNodeMethod(CharacterData.prototype, 'after');
      hardenNodeMethod(CharacterData.prototype, 'replaceWith');
    }

    if (typeof DocumentType === 'function') {
      hardenNodeMethod(DocumentType.prototype, 'before');
      hardenNodeMethod(DocumentType.prototype, 'after');
      hardenNodeMethod(DocumentType.prototype, 'replaceWith');
    }
  };
  const blockXSLTMaterialization = () => {
    const processor = window.XSLTProcessor;

    if (typeof processor !== 'function') {
      return;
    }

    defineValue(processor.prototype, 'transformToFragment', blockedConstructor);
    defineValue(processor.prototype, 'transformToDocument', blockedConstructor);
    defineValue(window, 'XSLTProcessor', blockedConstructor);
  };
  const blockNestedBrowsingContextCreation = () => {
    defineValue(Document.prototype, 'createElement', function createElement(name, options) {
      const normalizedName = `${name}`;

      if (isNestedBrowsingContextTag(normalizedName)) {
        return inertElement(this);
      }

      return nativeCreateElement.call(this, normalizedName, options);
    });
    defineValue(
      Document.prototype,
      'createElementNS',
      function createElementNS(namespace, qualifiedName, options) {
        const normalizedName = `${qualifiedName}`;

        if (isNestedBrowsingContextTag(normalizedName)) {
          return inertElement(this);
        }

        return nativeCreateElementNS.call(this, namespace, normalizedName, options);
      },
    );

    hardenMarkupSetter(Element.prototype, 'innerHTML');
    hardenMarkupSetter(Element.prototype, 'outerHTML');

    if (typeof ShadowRoot === 'function') {
      hardenMarkupSetter(ShadowRoot.prototype, 'innerHTML');
      hardenMarkupMethod(ShadowRoot.prototype, 'setHTMLUnsafe');
    }

    hardenMarkupMethod(Element.prototype, 'insertAdjacentHTML', 1);
    hardenMarkupMethod(Element.prototype, 'setHTMLUnsafe');
    hardenMarkupMethod(Range.prototype, 'createContextualFragment');
    const nativeParseFromString = DOMParser.prototype.parseFromString;
    defineValue(DOMParser.prototype, 'parseFromString', function parseFromString(markup, type) {
      const normalizedType = `${type}`.trim().toLowerCase();
      const source = normalizedType === 'text/html' ? sanitizeMarkup(markup) : markupString(markup);

      return nativeParseFromString.call(this, source, normalizedType);
    });
    // document.write is a streaming parser API: sanitizing individual arguments
    // cannot stop a tag split across arguments or calls. Disable it instead of
    // pretending a chunk-local rewrite is a security boundary.
    defineValue(Document.prototype, 'write', noop);
    defineValue(Document.prototype, 'writeln', noop);
    hardenMarkupMethod(Document, 'parseHTMLUnsafe');
    hardenNodeInsertionSinks();
    blockXSLTMaterialization();
  };
  const blockNavigationEvent = (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    return false;
  };
  const isRefreshMeta = (element) =>
    element.localName.toLowerCase() === 'meta' &&
    nativeGetAttribute.call(element, 'http-equiv')?.trim().toLowerCase() === 'refresh';
  const isResourceHintLink = (element) => {
    if (element.localName.toLowerCase() !== 'link') {
      return false;
    }

    const rels = (nativeGetAttribute.call(element, 'rel') ?? '').trim().toLowerCase().split(/\s+/u);

    return rels.some(
      (rel) =>
        rel === 'dns-prefetch' || rel === 'preconnect' || rel === 'prefetch' || rel === 'prerender',
    );
  };
  const inspectElement = (element) => {
    if (
      isNestedBrowsingContextElement(element) ||
      isRefreshMeta(element) ||
      isResourceHintLink(element)
    ) {
      removeElement(element);

      return false;
    }

    return true;
  };
  const inspectSubtree = (root) => {
    if (root instanceof Element && !inspectElement(root)) {
      return;
    }

    for (const element of elementsBelow(root)) {
      inspectElement(element);
    }
  };
  const watchDocument = () => {
    inspectSubtree(document);

    try {
      new MutationObserver((mutations) => {
        for (const mutation of mutations) {
          if (mutation.type === 'attributes' && mutation.target instanceof Element) {
            inspectElement(mutation.target);
            continue;
          }

          for (const node of mutation.addedNodes) {
            if (node instanceof Element || node instanceof DocumentFragment) {
              inspectSubtree(node);
            }
          }
        }
      }).observe(document.documentElement, {
        attributeFilter: ['http-equiv', 'rel'],
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
  blockNestedBrowsingContextCreation();

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
