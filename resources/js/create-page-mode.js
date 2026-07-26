import { creationModeForPageType, pageTypeForCreationMode } from './page-creation-selection';

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
  const htmlFileInput = form.querySelector('input[name="html_file"]');
  const imageFileInput = form.querySelector('input[name="image_file"]');
  const contentFields = form.querySelector('[data-create-page-content-fields]');
  const htmlUploadFields = form.querySelector('[data-create-page-upload-fields]');
  const imageUploadFields = form.querySelector('[data-create-page-image-upload-fields]');

  if (
    !(type instanceof HTMLSelectElement) ||
    !(mode instanceof HTMLSelectElement) ||
    !(title instanceof HTMLInputElement) ||
    !(htmlFileInput instanceof HTMLInputElement) ||
    !(imageFileInput instanceof HTMLInputElement) ||
    !(contentFields instanceof HTMLElement) ||
    !(htmlUploadFields instanceof HTMLElement) ||
    !(imageUploadFields instanceof HTMLElement)
  ) {
    return;
  }

  let suggestedTitle = '';

  const update = () => {
    // Only the content SOURCE swaps between the inline editor and the file upload.
    // The "Organize" metadata (status, tags, category, parent, description) stays
    // visible for every mode -- an uploaded artifact can be categorized and tagged
    // at creation just like a written page.
    const isHtmlUpload = type.value === 'html_artifact' && mode.value === 'html_upload';
    const isImageUpload = type.value === 'image' && mode.value === 'image_upload';

    contentFields.hidden = isHtmlUpload || isImageUpload;
    htmlUploadFields.hidden = !isHtmlUpload;
    imageUploadFields.hidden = !isImageUpload;
    htmlFileInput.required = isHtmlUpload;
    imageFileInput.required = isImageUpload;
  };

  type.addEventListener('change', () => {
    mode.value = creationModeForPageType(type.value, mode.value);

    update();
  });
  mode.addEventListener('change', () => {
    const nextType = pageTypeForCreationMode(mode.value);

    if (type.value !== nextType) {
      type.value = nextType;
      type.dispatchEvent(new Event('change'));
    }

    update();
  });
  const suggestTitle = (fileInput) => {
    const filename = fileInput.files?.[0]?.name;

    if (!filename) {
      return;
    }

    const nextSuggestedTitle = titleFromFilename(filename);

    if (title.value.trim() === '' || title.value === suggestedTitle) {
      title.value = nextSuggestedTitle;
      suggestedTitle = nextSuggestedTitle;
    }
  };

  htmlFileInput.addEventListener('change', () => suggestTitle(htmlFileInput));
  imageFileInput.addEventListener('change', () => suggestTitle(imageFileInput));

  mode.value = creationModeForPageType(type.value, mode.value);

  update();
  form.setAttribute('data-create-page-mode-ready', 'true');
}

for (const form of document.querySelectorAll('[data-create-page-form]')) {
  initialiseCreatePageMode(form);
}
