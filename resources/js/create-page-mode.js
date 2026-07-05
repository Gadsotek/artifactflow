function titleFromFilename(filename) {
  return filename
    .replace(/\.[^.]+$/u, '')
    .replace(/[-_]+/gu, ' ')
    .replace(/\s+/gu, ' ')
    .trim()
    .replace(/^\p{L}/u, (letter) => letter.toLocaleUpperCase());
}

function initialiseCreatePageMode(form) {
  const type = form.querySelector('select[name="type"]');
  const mode = form.querySelector('select[name="mode"]');
  const title = form.querySelector('input[name="title"]');
  const fileInput = form.querySelector('input[name="html_file"]');
  const contentFields = form.querySelector('[data-create-page-content-fields]');
  const uploadFields = form.querySelector('[data-create-page-upload-fields]');

  if (
    !(type instanceof HTMLSelectElement) ||
    !(mode instanceof HTMLSelectElement) ||
    !(title instanceof HTMLInputElement) ||
    !(fileInput instanceof HTMLInputElement) ||
    !(contentFields instanceof HTMLElement) ||
    !(uploadFields instanceof HTMLElement)
  ) {
    return;
  }

  let suggestedTitle = '';

  const update = () => {
    // Only the content SOURCE swaps between the inline editor and the file upload.
    // The "Organize" metadata (status, tags, category, parent, description) stays
    // visible for every mode -- an uploaded artifact can be categorized and tagged
    // at creation just like a written page.
    const isUpload = type.value === 'html_artifact' && mode.value === 'html_upload';

    contentFields.hidden = isUpload;
    uploadFields.hidden = !isUpload;
    fileInput.required = isUpload;
  };

  type.addEventListener('change', update);
  mode.addEventListener('change', update);
  fileInput.addEventListener('change', () => {
    const filename = fileInput.files?.[0]?.name;

    if (!filename) {
      return;
    }

    const nextSuggestedTitle = titleFromFilename(filename);

    if (title.value.trim() === '' || title.value === suggestedTitle) {
      title.value = nextSuggestedTitle;
      suggestedTitle = nextSuggestedTitle;
    }
  });

  update();
}

for (const form of document.querySelectorAll('[data-create-page-form]')) {
  initialiseCreatePageMode(form);
}
