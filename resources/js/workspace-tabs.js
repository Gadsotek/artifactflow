function initialiseWorkspaceTabs(container) {
  const tabs = Array.from(container.querySelectorAll('[data-workspace-tab]'));
  const panels = Array.from(container.querySelectorAll('[data-workspace-panel]'));

  const activate = (tabName, updateUrl = true) => {
    for (const tab of tabs) {
      const isActive = tab.getAttribute('data-workspace-tab') === tabName;

      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      tab.classList.toggle('is-active', isActive);
      tab.tabIndex = isActive ? 0 : -1;
    }

    for (const panel of panels) {
      panel.hidden = panel.getAttribute('data-workspace-panel') !== tabName;
    }

    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', tabName);

      if (tabName !== 'members') {
        url.searchParams.delete('members_page');
      }

      window.history.replaceState({}, '', url);
    }
  };

  for (const tab of tabs) {
    tab.addEventListener('click', () => {
      const tabName = tab.getAttribute('data-workspace-tab');

      if (tabName) {
        activate(tabName);
      }
    });

    tab.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        return;
      }

      event.preventDefault();
      const currentIndex = tabs.indexOf(tab);
      const direction = event.key === 'ArrowRight' ? 1 : -1;
      const nextIndex = (currentIndex + direction + tabs.length) % tabs.length;
      const nextTab = tabs[nextIndex];
      const tabName = nextTab?.getAttribute('data-workspace-tab');

      if (nextTab instanceof HTMLButtonElement && tabName) {
        activate(tabName);
        nextTab.focus();
      }
    });
  }
}

for (const container of document.querySelectorAll('[data-workspace-tabs]')) {
  initialiseWorkspaceTabs(container);
}
