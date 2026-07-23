const imagePreview = document.querySelector('#image-preview');
const previewImage = imagePreview?.querySelector('.preview-image');
const previewCaption = imagePreview?.querySelector('.preview-caption');
const previewOriginal = imagePreview?.querySelector('.preview-original');

if (
  imagePreview &&
  previewImage &&
  previewCaption &&
  previewOriginal &&
  typeof imagePreview.showModal === 'function'
) {
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

document.querySelectorAll('[data-mcp-animation]').forEach((animation) => {
  const playButton = animation.querySelector('[data-mcp-play]');
  const status = animation.querySelector('[data-mcp-status]');
  const steps = animation.querySelectorAll('[data-mcp-step]');

  if (!playButton || !status || steps.length === 0) {
    return;
  }

  const replayLabel = playButton.textContent;
  const staticStatus = status.textContent;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  playButton.addEventListener('click', () => {
    if (reducedMotion.matches) {
      if (animation.dataset.mcpLayout === 'session') {
        const isExpanded = animation.classList.toggle('is-expanded');
        playButton.textContent = isExpanded ? 'Reset static session' : replayLabel;
        status.textContent = isExpanded ? 'Full session shown without motion' : staticStatus;
      } else {
        status.textContent = 'Reduced motion is enabled; transcript remains static';
      }

      return;
    }

    animation.classList.remove('is-expanded', 'is-playing');
    void animation.offsetWidth;
    animation.classList.add('is-playing');
    playButton.disabled = true;
    playButton.textContent = 'Playing…';
    status.textContent =
      animation.dataset.mcpLayout === 'session'
        ? 'Running search → read → update → conflict'
        : 'Running search → read → update';

    const finalStep = steps.item(steps.length - 1);

    finalStep.addEventListener(
      'animationend',
      () => {
        window.setTimeout(() => {
          animation.classList.remove('is-playing');
          playButton.disabled = false;
          playButton.textContent = replayLabel;
          status.textContent = 'Replay complete';
        }, 1200);
      },
      { once: true },
    );
  });
});
