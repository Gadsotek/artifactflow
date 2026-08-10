let multiSelectId = 0;

function labelFor(select) {
  if (select.id === '') {
    return 'Options';
  }

  const label = document.querySelector(`label[for="${CSS.escape(select.id)}"]`);

  return label?.textContent?.trim() || 'Options';
}

function initialiseMultiSelect(select) {
  if (!(select instanceof HTMLSelectElement) || !select.multiple) {
    return;
  }

  multiSelectId += 1;
  const componentId = `af-multi-select-${multiSelectId}`;
  const wrapper = document.createElement('div');
  const trigger = document.createElement('button');
  const panel = document.createElement('div');
  const optionsList = document.createElement('div');
  const searchable = select.hasAttribute('data-searchable-multi-select');
  const entries = [];

  wrapper.className = 'af-multi-select';
  trigger.className = 'af-multi-select-trigger';
  trigger.type = 'button';
  trigger.dataset.multiSelectTrigger = '';
  trigger.setAttribute('aria-controls', componentId);
  trigger.setAttribute('aria-expanded', 'false');
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-label', labelFor(select));
  panel.className = 'af-multi-select-panel';
  panel.dataset.multiSelectPanel = '';
  panel.id = componentId;
  panel.hidden = true;
  optionsList.className = 'af-multi-select-options';
  optionsList.setAttribute('role', 'listbox');
  optionsList.setAttribute('aria-multiselectable', 'true');

  if (searchable) {
    const search = document.createElement('input');
    search.className = 'af-multi-select-search';
    search.dataset.multiSelectSearch = '';
    search.type = 'search';
    search.placeholder = select.dataset.searchPlaceholder || 'Search options';
    search.setAttribute('aria-label', search.placeholder);
    search.addEventListener('input', () => {
      const query = search.value.trim().toLocaleLowerCase();

      for (const entry of entries) {
        entry.row.hidden = query !== '' && !entry.searchText.includes(query);
      }
    });
    panel.append(search);
  }

  for (const option of select.options) {
    const row = document.createElement('label');
    const checkbox = document.createElement('input');
    const text = document.createElement('span');

    row.className = 'af-multi-select-option';
    row.dataset.multiSelectOption = '';
    row.dataset.value = option.value;
    row.setAttribute('role', 'option');
    checkbox.type = 'checkbox';
    checkbox.checked = option.selected;
    checkbox.disabled = option.disabled;
    text.textContent = option.textContent;
    row.append(checkbox, text);
    optionsList.append(row);

    const entry = {
      checkbox,
      option,
      row,
      searchText: `${option.textContent} ${option.value}`.toLocaleLowerCase(),
    };
    entries.push(entry);

    checkbox.addEventListener('change', () => {
      option.selected = checkbox.checked;
      row.setAttribute('aria-selected', checkbox.checked ? 'true' : 'false');
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  panel.append(optionsList);
  select.before(wrapper);
  wrapper.append(select, trigger, panel);
  if (select.id !== '') {
    trigger.id = select.id;
    select.removeAttribute('id');
  }
  select.tabIndex = -1;
  select.setAttribute('aria-hidden', 'true');
  select.classList.add('af-multi-select-native');

  const update = () => {
    const selected = entries.filter((entry) => entry.option.selected);

    for (const entry of entries) {
      entry.checkbox.checked = entry.option.selected;
      entry.row.setAttribute('aria-selected', entry.option.selected ? 'true' : 'false');
    }

    if (selected.length === 0) {
      trigger.textContent = select.dataset.placeholder || 'Any';
    } else if (selected.length <= 2) {
      trigger.textContent = selected.map((entry) => entry.option.textContent).join(', ');
    } else {
      trigger.textContent = `${selected.length} selected`;
    }
  };

  const close = () => {
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  };

  trigger.addEventListener('click', () => {
    const nextOpen = panel.hidden;

    for (const otherPanel of document.querySelectorAll('[data-multi-select-panel]')) {
      if (otherPanel instanceof HTMLElement && otherPanel !== panel) {
        otherPanel.hidden = true;
        otherPanel.parentElement
          ?.querySelector('[data-multi-select-trigger]')
          ?.setAttribute('aria-expanded', 'false');
      }
    }

    panel.hidden = !nextOpen;
    trigger.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');

    if (nextOpen) {
      panel.querySelector('input')?.focus();
    }
  });
  wrapper.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      close();
      trigger.focus();
    }
  });
  document.addEventListener('pointerdown', (event) => {
    if (event.target instanceof Node && !wrapper.contains(event.target)) {
      close();
    }
  });
  select.addEventListener('change', update);
  select.form?.addEventListener('reset', () => window.setTimeout(update));
  update();
  select.dataset.multiSelectReady = 'true';
}

for (const select of document.querySelectorAll('[data-multi-select]')) {
  initialiseMultiSelect(select);
}
