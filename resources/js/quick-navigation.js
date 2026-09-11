const dialog = document.querySelector('[data-quick-navigation]');
const input = dialog?.querySelector('[data-quick-query]');
const scope = dialog?.querySelector('[data-quick-workspace]');
const results = dialog?.querySelector('[data-quick-results]');
const status = dialog?.querySelector('[data-quick-status]');
const library = dialog?.querySelector('[data-quick-library]');
let controller;
let timer;
let revision = 0;
let returnFocus;

function closeSearch() {
  revision += 1;
  clearTimeout(timer);
  controller?.abort();
  dialog.close();
  returnFocus?.focus();
}

async function search() {
  controller?.abort();
  controller = new AbortController();
  const current = ++revision;
  results.replaceChildren();
  status.textContent = 'Finding pages…';
  const url = new URL(dialog.dataset.searchUrl, location.origin);
  url.searchParams.set('q', input.value.trim());
  url.searchParams.set('workspace_uid', scope.value);
  const libraryUrl = new URL('/pages', location.origin);
  libraryUrl.search = url.search;
  library.href = libraryUrl.href;
  try {
    const response = await fetch(url, {
      signal: controller.signal,
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Navigation unavailable');
    const data = await response.json();
    if (current !== revision || !dialog.open) return;
    for (const item of data.pages) {
      const destination = new URL(item.url, location.origin);
      if (
        destination.origin !== location.origin ||
        !/^\/pages\/[0-9a-z]{26}$/iu.test(destination.pathname)
      )
        continue;
      const link = document.createElement('a');
      link.href = destination.href;
      link.className = 'af-quick-result';
      const title = document.createElement('strong');
      title.textContent = item.title;
      const meta = document.createElement('span');
      meta.textContent = [item.type, item.workspace, item.favorite ? '★ Favorite' : null]
        .filter(Boolean)
        .join(' · ');
      link.append(title, meta);
      results.append(link);
    }
    status.textContent = results.childElementCount
      ? input.value.trim()
        ? `${results.childElementCount} matching pages`
        : 'Your recent pages and favorites'
      : input.value.trim()
        ? 'No matching titles. Try searching content in Library.'
        : 'Open or favorite a page to find it here.';
  } catch (error) {
    if (error.name !== 'AbortError' && current === revision)
      status.textContent = 'Search is unavailable. Try again or open Library.';
  }
}

function openSearch() {
  if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
  returnFocus =
    document.activeElement === document.body
      ? document.querySelector('[data-open-quick-navigation]')
      : document.activeElement;
  dialog.showModal();
  input.focus();
  void search();
}

for (const trigger of document.querySelectorAll('[data-open-quick-navigation]')) {
  trigger.addEventListener('click', openSearch);
}
dialog?.querySelector('[data-close-quick-navigation]')?.addEventListener('click', closeSearch);
dialog?.addEventListener('cancel', (event) => {
  event.preventDefault();
  closeSearch();
});
dialog?.addEventListener('click', (event) => {
  if (event.target === dialog) closeSearch();
});
input?.addEventListener('input', () => {
  revision += 1;
  controller?.abort();
  results.replaceChildren();
  clearTimeout(timer);
  timer = setTimeout(search, 160);
});
scope?.addEventListener('change', () => {
  clearTimeout(timer);
  void search();
});
document.addEventListener('keydown', (event) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !event.altKey) {
    // Keep focus inside another active modal, including unsaved content editors.
    if (document.querySelector('dialog[open]') && !dialog.open) return;
    event.preventDefault();
    openSearch();
  }
  if (!dialog?.open || !['ArrowDown', 'ArrowUp'].includes(event.key) || event.target === scope)
    return;
  const links = [...results.querySelectorAll('a')];
  if (!links.length) return;
  event.preventDefault();
  const index = links.indexOf(document.activeElement);
  const next =
    event.key === 'ArrowDown'
      ? (index + 1) % links.length
      : index <= 0
        ? links.length - 1
        : index - 1;
  links[next].focus();
});

const toggle = document.querySelector('[data-toggle-navigation]');
const sidebar = document.querySelector('[data-navigation-sidebar]');
toggle?.addEventListener('click', () => {
  const open = toggle.getAttribute('aria-expanded') !== 'true';
  sidebar.classList.toggle('is-open', open);
  toggle.setAttribute('aria-expanded', String(open));
  toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
});

if (dialog) dialog.dataset.quickNavigationReady = 'true';
