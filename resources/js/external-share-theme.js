const root = document.documentElement;
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
const storageKey = 'artifactflow.external-share.theme';
const themes = new Set(['light', 'dark', 'system']);

function storedTheme() {
  try {
    const value = window.localStorage.getItem(storageKey);

    return themes.has(value) ? value : 'system';
  } catch {
    return 'system';
  }
}

function applyTheme(theme) {
  root.dataset.theme = theme;
  root.classList.toggle('dark', theme === 'dark' || (theme === 'system' && systemTheme.matches));

  for (const button of document.querySelectorAll('[data-external-share-theme]')) {
    if (button instanceof HTMLButtonElement) {
      const active = button.value === theme;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
  }
}

function selectTheme(theme) {
  if (!themes.has(theme)) {
    return;
  }

  try {
    window.localStorage.setItem(storageKey, theme);
  } catch {
    // The selected theme still applies for this page when storage is blocked.
  }

  applyTheme(theme);
}

for (const button of document.querySelectorAll('[data-external-share-theme]')) {
  button.addEventListener('click', () => {
    if (button instanceof HTMLButtonElement) {
      selectTheme(button.value);
    }
  });
}

systemTheme.addEventListener('change', () => {
  if (root.dataset.theme === 'system') {
    applyTheme('system');
  }
});

applyTheme(themes.has(root.dataset.theme) ? root.dataset.theme : storedTheme());
