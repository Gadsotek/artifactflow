import { creationModeForPageType } from './page-creation-selection';

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
  const pdfFileInput = form.querySelector('input[name="pdf_file"]');
  const xlsxFileInput = form.querySelector('input[name="xlsx_file"]');
  const docxFileInput = form.querySelector('input[name="docx_file"]');
  const contentFields = form.querySelector('[data-create-page-content-fields]');
  const htmlUploadFields = form.querySelector('[data-create-page-upload-fields]');
  const imageUploadFields = form.querySelector('[data-create-page-image-upload-fields]');
  const pdfUploadFields = form.querySelector('[data-create-page-pdf-upload-fields]');
  const xlsxUploadFields = form.querySelector('[data-create-page-xlsx-upload-fields]');
  const docxUploadFields = form.querySelector('[data-create-page-docx-upload-fields]');

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
    for (const option of mode.querySelectorAll('[data-create-page-mode-type]')) {
      if (!(option instanceof HTMLOptionElement)) {
        continue;
      }

      const available = option.dataset.createPageModeType === type.value;
      option.disabled = !available;
      option.hidden = !available;
    }

    mode.value = creationModeForPageType(type.value, mode.value);

    const isHtmlUpload = type.value === 'html_artifact' && mode.value === 'html_upload';
    const isImageUpload = type.value === 'image' && mode.value === 'image_upload';
    const isPdfUpload = type.value === 'pdf' && mode.value === 'pdf_upload';
    const isXlsxUpload = type.value === 'xlsx' && mode.value === 'xlsx_upload';
    const isDocxUpload = type.value === 'docx' && mode.value === 'docx_upload';

    contentFields.hidden =
      isHtmlUpload || isImageUpload || isPdfUpload || isXlsxUpload || isDocxUpload;
    htmlUploadFields.hidden = !isHtmlUpload;
    imageUploadFields.hidden = !isImageUpload;
    if (pdfUploadFields instanceof HTMLElement) {
      pdfUploadFields.hidden = !isPdfUpload;
    }
    if (xlsxUploadFields instanceof HTMLElement) {
      xlsxUploadFields.hidden = !isXlsxUpload;
    }
    if (docxUploadFields instanceof HTMLElement) {
      docxUploadFields.hidden = !isDocxUpload;
    }
    htmlFileInput.required = isHtmlUpload;
    imageFileInput.required = isImageUpload;
    if (pdfFileInput instanceof HTMLInputElement) {
      pdfFileInput.required = isPdfUpload;
    }
    if (xlsxFileInput instanceof HTMLInputElement) {
      xlsxFileInput.required = isXlsxUpload;
    }
    if (docxFileInput instanceof HTMLInputElement) {
      docxFileInput.required = isDocxUpload;
    }
  };

  type.addEventListener('change', () => {
    update();
  });
  mode.addEventListener('change', update);
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
  pdfFileInput?.addEventListener('change', () => suggestTitle(pdfFileInput));
  xlsxFileInput?.addEventListener('change', () => suggestTitle(xlsxFileInput));
  docxFileInput?.addEventListener('change', () => suggestTitle(docxFileInput));

  update();
  form.setAttribute('data-create-page-mode-ready', 'true');
}

for (const form of document.querySelectorAll('[data-create-page-form]')) {
  initialiseCreatePageMode(form);
}
