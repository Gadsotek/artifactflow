import './theme';

if (document.querySelector('[data-content-editor]')) {
  void import('./content-editor');
}

if (document.querySelector('[data-create-page-form]')) {
  void import('./create-page-mode');
}

if (document.querySelector('[data-create-page-category]')) {
  void import('./create-page-category');
}

if (document.querySelector('[data-workspace-tabs]')) {
  void import('./workspace-tabs');
}

if (document.querySelector('[data-page-workspace-move-form]')) {
  void import('./page-workspace-move');
}

if (document.querySelector('[data-html-draft-preview]')) {
  void import('./html-draft-preview');
}

if (document.querySelector('[data-editor-dialog]')) {
  void import('./editor-dialog');
}

if (document.querySelector('[data-known-user-picker]')) {
  void import('./known-user-autocomplete');
}

if (document.querySelector('[data-copy-page-link]')) {
  void import('./copy-page-link');
}

if (document.querySelector('[data-two-factor-challenge]')) {
  void import('./two-factor-challenge');
}

if (document.querySelector('[data-two-factor-enrollment-timer]')) {
  void import('./two-factor-enrollment-timer');
}

if (document.querySelector('[data-realtime-enabled="true"][data-realtime-config]')) {
  void import('./realtime').then(() => {
    if (document.querySelector('[data-page-presence]')) {
      void import('./page-presence');
    }
  });
}

if (document.querySelector('[data-mermaid-diagram]')) {
  void import('./mermaid-renderer').then(({ renderMermaidDiagrams }) => renderMermaidDiagrams());
}

if (document.querySelector('[data-artifact-preview]')) {
  void import('./artifact-fullscreen');
  void import('./artifact-preview-refresh');
}
