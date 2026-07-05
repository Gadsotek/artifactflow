import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { Compartment } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { tags } from '@lezer/highlight';
import { basicSetup } from 'codemirror';
import { appCspNonce } from './csp-nonce';
import { initialiseRichMarkdownEditor } from './rich-markdown-editor';

const editorTheme = EditorView.theme({
  '&': {
    minHeight: '36rem',
    backgroundColor: '#fff',
    color: '#18181b',
    fontSize: '0.875rem',
  },
  '.cm-content': {
    caretColor: '#0f766e',
    color: '#18181b',
    fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
    lineHeight: '1.65',
    minHeight: '36rem',
    padding: '1rem 0',
  },
  '.cm-cursor, .cm-dropCursor': {
    borderLeftColor: '#0f766e',
  },
  '.cm-gutters': {
    backgroundColor: 'rgb(250 250 250)',
    borderRight: '1px solid rgb(212 212 216)',
    color: 'rgb(113 113 122)',
    minWidth: '3.5rem',
  },
  '.cm-lineNumbers .cm-gutterElement': {
    minWidth: '2.75rem',
    padding: '0 0.75rem 0 0.5rem',
    textAlign: 'right',
  },
  '.cm-foldGutter .cm-gutterElement': {
    padding: '0 0.25rem',
  },
  '.cm-activeLine, .cm-activeLineGutter': {
    backgroundColor: 'rgba(20, 184, 166, 0.08)',
  },
  '&.cm-focused': {
    outline: '2px solid rgba(13, 148, 136, 0.45)',
    outlineOffset: '-2px',
  },
});

const darkEditorTheme = EditorView.theme(
  {
    '&': {
      backgroundColor: 'rgb(24 24 27)',
      color: 'rgb(244 244 245)',
    },
    '.cm-content': {
      color: 'rgb(244 244 245)',
    },
    '.cm-gutters': {
      backgroundColor: 'rgb(9 9 11)',
      borderRightColor: 'rgb(63 63 70)',
      color: 'rgb(161 161 170)',
    },
    '.cm-selectionBackground, ::selection': {
      backgroundColor: 'rgba(13, 148, 136, 0.35) !important',
    },
  },
  { dark: true },
);

const lightHighlightStyle = HighlightStyle.define([
  { tag: tags.heading, color: '#0f766e', fontWeight: '700' },
  { tag: [tags.keyword, tags.tagName], color: '#7c3aed', fontWeight: '600' },
  { tag: [tags.attributeName, tags.propertyName], color: '#0369a1' },
  { tag: [tags.string, tags.special(tags.string)], color: '#047857' },
  { tag: [tags.number, tags.bool, tags.null], color: '#c2410c' },
  { tag: [tags.comment, tags.meta], color: '#71717a', fontStyle: 'italic' },
  { tag: [tags.link, tags.url], color: '#0f766e', textDecoration: 'underline' },
  { tag: tags.emphasis, fontStyle: 'italic' },
  { tag: tags.strong, fontWeight: '700' },
  { tag: [tags.bracket, tags.punctuation], color: '#52525b' },
]);

const darkHighlightStyle = HighlightStyle.define([
  { tag: tags.heading, color: '#5eead4', fontWeight: '700' },
  { tag: [tags.keyword, tags.tagName], color: '#c4b5fd', fontWeight: '600' },
  { tag: [tags.attributeName, tags.propertyName], color: '#7dd3fc' },
  { tag: [tags.string, tags.special(tags.string)], color: '#6ee7b7' },
  { tag: [tags.number, tags.bool, tags.null], color: '#fdba74' },
  { tag: [tags.comment, tags.meta], color: '#a1a1aa', fontStyle: 'italic' },
  { tag: [tags.link, tags.url], color: '#5eead4', textDecoration: 'underline' },
  { tag: tags.emphasis, fontStyle: 'italic' },
  { tag: tags.strong, fontWeight: '700' },
  { tag: [tags.bracket, tags.punctuation], color: '#d4d4d8' },
]);

async function languageExtension(language) {
  if (language === 'html') {
    const { html } = await import('@codemirror/lang-html');
    return html();
  }

  const { markdown } = await import('@codemirror/lang-markdown');
  return markdown();
}

function normalisedLanguage(language) {
  return language === 'html' || language === 'html_artifact' ? 'html' : 'markdown';
}

function cspNonceExtension() {
  const nonce = appCspNonce();

  return nonce ? [EditorView.cspNonce.of(nonce)] : [];
}

async function showSavedPage(response) {
  // Prefer a real navigation to the saved page: it resets JS state and
  // listeners cleanly and keeps history/back-button semantics.
  if (response.url) {
    window.location.assign(response.url);

    return;
  }

  // A followed same-origin fetch always exposes response.url, so this branch is
  // defensive only. Reload the current page rather than resurrecting the
  // deprecated document.open/write/close rebuild.
  window.location.reload();
}

async function submitContentForm(form, status, concurrencyError) {
  let response;

  try {
    response = await fetch(form.action, {
      method: form.method || 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {
        Accept: 'text/html',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
  } catch {
    if (concurrencyError instanceof HTMLElement) {
      concurrencyError.textContent =
        'The page could not be saved. Check your connection and try again.';
      concurrencyError.hidden = false;
    }

    if (status instanceof HTMLElement) {
      status.textContent = 'Save failed';
    }

    return;
  }

  if (response.status === 409) {
    const message = (await response.text()).trim() || 'This page changed since you opened it.';

    if (concurrencyError instanceof HTMLElement) {
      concurrencyError.textContent = message;
      concurrencyError.hidden = false;
    }

    if (status instanceof HTMLElement) {
      status.textContent = 'Save blocked';
    }

    return;
  }

  if (response.ok) {
    await showSavedPage(response);

    return;
  }

  const body = (await response.text()).trim();
  const detail = body === '' ? '' : `: ${body.slice(0, 200)}`;

  if (concurrencyError instanceof HTMLElement) {
    concurrencyError.textContent = `The page could not be saved (HTTP ${response.status})${detail}`;
    concurrencyError.hidden = false;
  }

  if (status instanceof HTMLElement) {
    status.textContent = `Save failed (HTTP ${response.status})`;
  }
}

async function initialiseSourceEditor(form, textarea, mount, language, status, count) {
  const languageCompartment = new Compartment();
  const editorLanguage = await languageExtension(language);

  const updateCount = (documentText) => {
    if (count instanceof HTMLElement) {
      count.textContent = `${documentText.lines} lines · ${documentText.length} characters`;
    }
  };

  const view = new EditorView({
    doc: textarea.value,
    parent: mount,
    extensions: [
      basicSetup,
      ...cspNonceExtension(),
      languageCompartment.of(editorLanguage),
      EditorView.lineWrapping,
      editorTheme,
      ...(document.documentElement.classList.contains('dark')
        ? [darkEditorTheme, syntaxHighlighting(darkHighlightStyle)]
        : [syntaxHighlighting(lightHighlightStyle)]),
      keymap.of([
        {
          key: 'Mod-s',
          preventDefault: true,
          run: () => {
            form.requestSubmit();
            return true;
          },
        },
      ]),
      EditorView.updateListener.of((update) => {
        if (!update.docChanged) {
          return;
        }

        textarea.value = update.state.doc.toString();
        updateCount(update.state.doc);

        if (status instanceof HTMLElement) {
          status.textContent = 'Unsaved changes';
        }
      }),
    ],
  });

  mount.dataset.editorEnhanced = 'true';
  mount.addEventListener('artifactflow:editor-visible', () => {
    view.requestMeasure();
    view.focus();
  });
  updateCount(view.state.doc);

  return {
    focus() {
      view.requestMeasure();
      view.focus();
    },
    async setLanguage(nextLanguage) {
      view.dispatch({
        effects: languageCompartment.reconfigure(await languageExtension(nextLanguage)),
      });
    },
    setValue(value) {
      const currentValue = view.state.doc.toString();

      if (currentValue === value) {
        return;
      }

      view.dispatch({
        changes: { from: 0, to: currentValue.length, insert: value },
      });
    },
    sync() {
      textarea.value = view.state.doc.toString();
    },
  };
}

async function initialiseContentEditor(form) {
  const textarea = form.querySelector('[data-editor-textarea]');
  const sourceMount = form.querySelector('[data-source-editor-mount]');
  const richMount = form.querySelector('[data-rich-markdown-editor]');

  if (!(textarea instanceof HTMLTextAreaElement)) {
    return;
  }

  const status = form.querySelector('[data-editor-status]');
  const count = form.querySelector('[data-editor-count]');
  const concurrencyError = form.querySelector('[data-concurrency-error]');
  const languageLabel = form.querySelector('[data-editor-language-label]');
  const markdownToolbar = form.querySelector('[data-markdown-toolbar]');
  const viewSwitch = form.querySelector('[data-editor-view-switch]');
  const viewButtons = Array.from(form.querySelectorAll('[data-editor-view-button]'));
  const languageSelectName = form.dataset.editorLanguageSelect;
  const languageSelect = languageSelectName
    ? form.querySelector(`[name="${languageSelectName}"]`)
    : null;
  const initialLanguage =
    languageSelect instanceof HTMLSelectElement
      ? normalisedLanguage(languageSelect.value)
      : normalisedLanguage(form.dataset.editorLanguage);
  const sourceEditor =
    sourceMount instanceof HTMLElement
      ? await initialiseSourceEditor(form, textarea, sourceMount, initialLanguage, status, count)
      : null;
  const richEditor =
    richMount instanceof HTMLElement
      ? initialiseRichMarkdownEditor(form, textarea, richMount, status, count)
      : null;
  let activeLanguage = initialLanguage;
  let activeView = form.dataset.editorLayout === 'source' ? 'source' : 'rich';
  let presentationInitialised = false;

  const updatePresentation = async (nextLanguage, requestedView = activeView) => {
    const shouldFocusEditor = presentationInitialised;
    form.dataset.editorReady = 'false';

    if (presentationInitialised) {
      if (activeLanguage === 'markdown' && activeView === 'rich' && richEditor !== null) {
        richEditor.sync();
      } else if (sourceEditor !== null) {
        sourceEditor.sync();
      }
    }

    activeLanguage = nextLanguage;
    activeView =
      nextLanguage === 'html' ? 'source' : requestedView === 'source' ? 'source' : 'rich';

    if (languageLabel instanceof HTMLElement) {
      languageLabel.textContent =
        nextLanguage === 'html'
          ? 'HTML source'
          : activeView === 'source'
            ? 'Markdown source'
            : 'Rich Markdown';
    }

    if (markdownToolbar instanceof HTMLElement) {
      markdownToolbar.hidden = nextLanguage !== 'markdown' || activeView !== 'rich';
    }

    if (richMount instanceof HTMLElement) {
      richMount.hidden = nextLanguage !== 'markdown' || activeView !== 'rich';
    }

    if (sourceMount instanceof HTMLElement) {
      sourceMount.hidden = nextLanguage === 'markdown' && activeView !== 'source';
    }

    if (viewSwitch instanceof HTMLElement) {
      viewSwitch.hidden =
        nextLanguage !== 'markdown' || sourceEditor === null || richEditor === null;
    }

    for (const button of viewButtons) {
      const isActive = button.getAttribute('data-editor-view') === activeView;

      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      button.dataset.active = isActive ? 'true' : 'false';
    }

    form.dataset.editorLanguage = nextLanguage;

    if (nextLanguage === 'markdown' && activeView === 'rich' && richEditor !== null) {
      richEditor.activate(textarea.value, shouldFocusEditor);
    } else if (sourceEditor !== null) {
      await sourceEditor.setLanguage(nextLanguage);
      sourceEditor.setValue(textarea.value);

      if (shouldFocusEditor) {
        sourceEditor.focus();
      }
    }

    presentationInitialised = true;
    form.dataset.editorReady = 'true';
  };

  textarea.hidden = true;

  if (languageSelect instanceof HTMLSelectElement) {
    languageSelect.addEventListener('change', () => {
      const nextLanguage = normalisedLanguage(languageSelect.value);
      void updatePresentation(nextLanguage, nextLanguage === 'markdown' ? 'rich' : 'source');
    });
  }

  for (const button of viewButtons) {
    button.addEventListener('click', () => {
      const requestedView = button.getAttribute('data-editor-view');

      if (requestedView === 'rich' || requestedView === 'source') {
        void updatePresentation(activeLanguage, requestedView);
      }
    });
  }

  form.addEventListener('submit', (event) => {
    if (activeLanguage === 'markdown' && activeView === 'rich' && richEditor !== null) {
      richEditor.sync();
    } else {
      sourceEditor?.sync();
    }

    if (concurrencyError instanceof HTMLElement) {
      concurrencyError.hidden = true;
      concurrencyError.textContent = '';
    }

    if (status instanceof HTMLElement) {
      status.textContent = 'Saving…';
    }

    if (typeof window.fetch !== 'function') {
      return;
    }

    event.preventDefault();
    void submitContentForm(form, status, concurrencyError);
  });

  await updatePresentation(initialLanguage, activeView);
}

for (const form of document.querySelectorAll('[data-content-editor]')) {
  void initialiseContentEditor(form);
}
