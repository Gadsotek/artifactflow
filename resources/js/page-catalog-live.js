const initialMount = document.querySelector('[data-live-page-catalog]');
const { Echo } = window;

if (initialMount instanceof HTMLElement && Echo) {
  const userUid = initialMount.dataset.livePageCatalogUserUid ?? '';
  let refreshInFlight = false;
  let refreshQueued = false;

  const refreshCatalog = async () => {
    if (refreshInFlight) {
      refreshQueued = true;

      return;
    }

    refreshInFlight = true;

    try {
      const response = await window.fetch(window.location.href, {
        method: 'GET',
        headers: {
          Accept: 'text/html',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });
      const contentType = response.headers.get('Content-Type') ?? '';

      if (!response.ok || !contentType.includes('text/html')) {
        return;
      }

      const documentSnapshot = new DOMParser().parseFromString(await response.text(), 'text/html');
      const currentCatalog = document.querySelector('[data-live-page-catalog]');
      const nextCatalog = documentSnapshot.querySelector('[data-live-page-catalog]');

      if (currentCatalog instanceof HTMLElement && nextCatalog instanceof HTMLElement) {
        currentCatalog.replaceWith(nextCatalog);
      }
    } catch {
      // The normal server-rendered catalog remains usable when a live refresh fails.
    } finally {
      refreshInFlight = false;

      if (refreshQueued) {
        refreshQueued = false;
        void refreshCatalog();
      }
    }
  };

  if (userUid !== '') {
    Echo.private(`user.${userUid}.page-catalog`).listen('.page.created', (payload) => {
      const currentCatalog = document.querySelector('[data-live-page-catalog]');
      const visibleWorkspaceUid =
        currentCatalog instanceof HTMLElement
          ? (currentCatalog.dataset.livePageCatalogWorkspaceUid ?? '')
          : '';

      if (
        !payload ||
        typeof payload.page_uid !== 'string' ||
        typeof payload.workspace_uid !== 'string' ||
        (visibleWorkspaceUid !== 'all' && payload.workspace_uid !== visibleWorkspaceUid)
      ) {
        return;
      }

      void refreshCatalog();
    });
  }
}
