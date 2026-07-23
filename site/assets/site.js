const imagePreview = document.querySelector('#image-preview');
const previewImage = imagePreview?.querySelector('.preview-image');
const previewCaption = imagePreview?.querySelector('.preview-caption');
const previewOriginal = imagePreview?.querySelector('.preview-original');

if (imagePreview && previewImage && previewCaption && previewOriginal && typeof imagePreview.showModal === 'function') {
  document.querySelectorAll('.preview-trigger').forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      const thumbnail = trigger.querySelector('img');

      if (!thumbnail) {
        return;
      }

      event.preventDefault();
      previewImage.src = trigger.href;
      previewImage.alt = thumbnail.alt;
      previewCaption.textContent = trigger.dataset.previewCaption ?? thumbnail.alt;
      previewOriginal.href = trigger.href;
      imagePreview.showModal();
    });
  });

  imagePreview.addEventListener('click', (event) => {
    if (event.target === imagePreview) {
      imagePreview.close();
    }
  });
}
