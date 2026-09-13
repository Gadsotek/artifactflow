for (const navigation of document.querySelectorAll('[data-workspace-navigation]')) {
  const input = navigation.querySelector('[data-workspace-search]');
  input?.addEventListener('input', () => {
    const query = input.value.trim().toLocaleLowerCase();
    let visible = 0;
    for (const option of navigation.querySelectorAll('[data-workspace-option]')) {
      option.hidden = !option.dataset.workspaceLabel.toLocaleLowerCase().includes(query);
      if (!option.hidden) visible += 1;
    }
    navigation.querySelector('[data-workspace-empty]').hidden = visible > 0;
  });
}

const actor = document.querySelector('[data-navigation-user]')?.dataset.navigationUser;
for (const menu of document.querySelectorAll('.af-more-tools')) {
  document.addEventListener('click', (event) => {
    if (!menu.contains(event.target)) menu.open = false;
  });
  menu.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      menu.open = false;
      menu.querySelector('summary').focus();
    }
  });
}
const key = `artifactflow-library-return:${actor}`;
const allowed = new Set(['/pages', '/recent', '/favorites', '/dashboard']);
for (const link of document.querySelectorAll(
  '[data-library-results] a[data-page-uid], .af-navigation-page',
)) {
  link.addEventListener('click', () => {
    if (!actor || !allowed.has(location.pathname)) return;
    try {
      sessionStorage.setItem(key, location.pathname + location.search);
    } catch {
      /* Optional navigation convenience. */
    }
  });
}
const back = document.querySelector('[data-back-to-library]');
if (actor && back) {
  try {
    const saved = sessionStorage.getItem(key);
    if (saved && saved.length <= 4096) {
      const url = new URL(saved, location.origin);
      if (url.origin === location.origin && allowed.has(url.pathname)) {
        back.href = url.href;
        back.textContent =
          url.pathname === '/recent'
            ? '← Recently opened'
            : url.pathname === '/favorites'
              ? '← Favorites'
              : url.pathname === '/dashboard'
                ? '← Home'
                : '← Back to results';
      }
    }
  } catch {
    /* The default Library link remains available. */
  }
}
