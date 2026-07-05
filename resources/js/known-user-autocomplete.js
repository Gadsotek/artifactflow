// Reusable autocomplete for selecting an existing, server-scoped collaborator.
// Each search endpoint is responsible for returning only people the actor is
// allowed to discover. Server text is always rendered with textContent.

const SEARCH_DEBOUNCE_MS = 200;
const MIN_QUERY_LENGTH = 1;

function setupPicker(form) {
  const searchUrl = form.getAttribute('data-search-url');
  const valueKey = form.getAttribute('data-known-user-value-key') ?? 'uid';
  const requiresSelection = form.hasAttribute('data-known-user-require-selection');
  const searchInput = form.querySelector('[data-known-user-search]');
  const resultsList = form.querySelector('[data-known-user-results]');
  const emptyNote = form.querySelector('[data-known-user-empty]');
  const valueInput = form.querySelector('[data-known-user-value]');
  const selectedNote = form.querySelector('[data-known-user-selected]');
  const selectedLabel = form.querySelector('[data-known-user-selected-label]');
  const submitButton = form.querySelector('[data-known-user-submit]');

  if (!searchUrl || !searchInput || !resultsList || !valueInput || !submitButton) {
    return;
  }

  let debounceTimer = null;
  let latestRequest = 0;

  function clearSelection() {
    if (valueInput !== searchInput) {
      valueInput.value = '';
    }

    submitButton.disabled = requiresSelection;
    selectedNote?.classList.add('hidden');
    if (selectedLabel) {
      selectedLabel.textContent = '';
    }
  }

  function hideResults() {
    resultsList.replaceChildren();
    resultsList.classList.add('hidden');
    searchInput.setAttribute('aria-expanded', 'false');
  }

  function showEmpty(isEmpty) {
    emptyNote?.classList.toggle('hidden', !isEmpty);
  }

  function selectPerson(person) {
    const selectedValue = person[valueKey];

    if (typeof selectedValue !== 'string') {
      return;
    }

    valueInput.value = selectedValue;
    if (selectedLabel) {
      selectedLabel.textContent = `${person.name} (${person.email})`;
    }
    selectedNote?.classList.remove('hidden');
    submitButton.disabled = false;
    searchInput.value = valueInput === searchInput ? selectedValue : person.name;
    hideResults();
    showEmpty(false);
  }

  function renderResults(results) {
    resultsList.replaceChildren();

    for (const person of results) {
      if (!person || typeof person[valueKey] !== 'string') {
        continue;
      }

      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'af-collaborator-option';
      option.setAttribute('role', 'option');

      const name = document.createElement('span');
      name.className = 'af-collaborator-option-name';
      name.textContent = typeof person.name === 'string' ? person.name : '';

      const email = document.createElement('span');
      email.className = 'af-collaborator-option-email';
      email.textContent = typeof person.email === 'string' ? person.email : '';

      option.append(name, email);
      option.addEventListener('click', () => selectPerson(person));

      const item = document.createElement('li');
      item.append(option);
      resultsList.append(item);
    }

    const hasResults = resultsList.childElementCount > 0;
    resultsList.classList.toggle('hidden', !hasResults);
    searchInput.setAttribute('aria-expanded', hasResults ? 'true' : 'false');
    showEmpty(!hasResults);
  }

  async function runSearch(query) {
    const requestId = ++latestRequest;
    const url = new URL(searchUrl, window.location.origin);
    url.searchParams.set('q', query);

    try {
      const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });

      if (!response.ok || requestId !== latestRequest) {
        return;
      }

      const payload = await response.json();

      if (requestId !== latestRequest) {
        return;
      }

      renderResults(Array.isArray(payload.results) ? payload.results : []);
    } catch {
      // Leave the current state untouched on a transient network failure.
    }
  }

  searchInput.addEventListener('input', () => {
    clearSelection();

    if (debounceTimer !== null) {
      window.clearTimeout(debounceTimer);
    }

    const query = searchInput.value.trim();

    if (query.length < MIN_QUERY_LENGTH) {
      latestRequest += 1;
      hideResults();
      showEmpty(false);
      return;
    }

    debounceTimer = window.setTimeout(() => runSearch(query), SEARCH_DEBOUNCE_MS);
  });

  form.addEventListener('submit', (event) => {
    if (requiresSelection && valueInput.value === '') {
      event.preventDefault();
    }
  });

  clearSelection();
  form.setAttribute('data-known-user-picker-ready', '');
}

for (const form of document.querySelectorAll('[data-known-user-picker]')) {
  setupPicker(form);
}
