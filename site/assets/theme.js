(() => {
  'use strict';

  const storageKey = 'artifactflow-theme';
  const root = document.documentElement;
  const systemPreference = window.matchMedia('(prefers-color-scheme: dark)');

  const storedTheme = () => {
    try {
      const theme = window.localStorage.getItem(storageKey);

      return theme === 'light' || theme === 'dark' ? theme : null;
    } catch {
      return null;
    }
  };

  const updateControls = (theme) => {
    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      const nextTheme = theme === 'dark' ? 'light' : 'dark';
      const label = `Switch to ${nextTheme} theme`;

      toggle.setAttribute('aria-pressed', String(theme === 'dark'));
      toggle.setAttribute('aria-label', label);
      toggle.setAttribute('title', label);
    });

    document.querySelectorAll('meta[data-theme-color]').forEach((meta) => {
      meta.setAttribute('media', meta.dataset.themeColor === theme ? 'all' : 'not all');
    });
  };

  const applyTheme = (theme, persist = false) => {
    root.dataset.theme = theme;
    root.style.colorScheme = theme;

    if (persist) {
      try {
        window.localStorage.setItem(storageKey, theme);
      } catch {
        // The selected theme still applies for this page when storage is unavailable.
      }
    }

    updateControls(theme);
  };

  applyTheme(storedTheme() ?? (systemPreference.matches ? 'dark' : 'light'));

  document.addEventListener('DOMContentLoaded', () => {
    updateControls(root.dataset.theme);

    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
      });
    });
  });

  const followSystemPreference = (event) => {
    if (storedTheme() === null) {
      applyTheme(event.matches ? 'dark' : 'light');
    }
  };

  if (typeof systemPreference.addEventListener === 'function') {
    systemPreference.addEventListener('change', followSystemPreference);
  } else {
    systemPreference.addListener(followSystemPreference);
  }
})();
