const root = document.documentElement;
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');

function applyResolvedTheme() {
  const useDark =
    root.dataset.theme === 'dark' || (root.dataset.theme === 'system' && systemTheme.matches);

  root.classList.toggle('dark', useDark);
}

systemTheme.addEventListener('change', () => {
  if (root.dataset.theme === 'system') {
    applyResolvedTheme();
  }
});

applyResolvedTheme();
