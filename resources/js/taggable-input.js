function initialTags(input) {
  return input.value
    .split(',')
    .map((tag) => tag.trim())
    .filter((tag, index, tags) => tag !== '' && tags.indexOf(tag) === index);
}

function initialiseTaggableInput(input) {
  if (!(input instanceof HTMLInputElement)) {
    return;
  }

  const shell = document.createElement('div');
  const chips = document.createElement('div');
  const entry = document.createElement('input');
  let tags = initialTags(input);

  shell.className = 'af-taggable-input';
  chips.className = 'af-taggable-chips';
  chips.dataset.taggableChips = '';
  entry.className = 'af-taggable-entry';
  entry.dataset.taggableEntry = '';
  entry.type = 'text';
  entry.maxLength = 80;
  entry.placeholder = input.placeholder || 'Add a tag';
  entry.setAttribute('aria-label', 'Add a tag');

  if (input.id !== '') {
    entry.id = input.id;
    input.removeAttribute('id');
  }

  input.tabIndex = -1;
  input.setAttribute('aria-hidden', 'true');
  input.before(shell);
  shell.append(chips, entry, input);
  input.classList.add('af-taggable-native');

  const render = () => {
    chips.replaceChildren();
    input.value = tags.join(', ');

    for (const tag of tags) {
      const chip = document.createElement('span');
      const label = document.createElement('span');
      const remove = document.createElement('button');

      chip.className = 'af-taggable-chip';
      chip.dataset.taggableChip = '';
      label.textContent = tag;
      remove.type = 'button';
      remove.dataset.taggableRemove = tag;
      remove.setAttribute('aria-label', `Remove ${tag}`);
      remove.textContent = '×';
      chip.append(label, remove);
      chips.append(chip);
    }
  };

  const removeTag = (event) => {
    if (!(event.target instanceof Element)) {
      return;
    }

    const remove = event.target.closest('[data-taggable-remove]');

    if (!(remove instanceof HTMLButtonElement) || !chips.contains(remove)) {
      return;
    }

    tags = tags.filter((tag) => tag !== remove.dataset.taggableRemove);
    render();
    entry.focus();
  };

  chips.addEventListener('pointerdown', (event) => {
    event.preventDefault();
    removeTag(event);
  });
  chips.addEventListener('click', removeTag);

  const add = () => {
    const tag = entry.value.trim();

    if (tag === '') {
      return;
    }

    if (!tags.some((current) => current.toLocaleLowerCase() === tag.toLocaleLowerCase())) {
      tags.push(tag);
      render();
    }

    entry.value = '';
  };

  entry.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      add();
    } else if (event.key === 'Backspace' && entry.value === '' && tags.length > 0) {
      tags = tags.slice(0, -1);
      render();
    }
  });
  entry.addEventListener('blur', add);
  entry.addEventListener('paste', (event) => {
    const pasted = event.clipboardData?.getData('text') ?? '';

    if (!pasted.includes(',')) {
      return;
    }

    event.preventDefault();

    for (const tag of pasted.split(',')) {
      entry.value = tag;
      add();
    }
  });
  input.form?.addEventListener('submit', add);
  input.form?.addEventListener('reset', () => {
    window.setTimeout(() => {
      tags = initialTags(input);
      render();
    });
  });
  render();
  input.dataset.taggableReady = 'true';
}

for (const input of document.querySelectorAll('[data-taggable-input]')) {
  initialiseTaggableInput(input);
}
