const wrapper = document.querySelector('[data-artifact-preview]');
const toggle = document.querySelector('[data-artifact-fullscreen-toggle]');

if (wrapper instanceof HTMLElement && toggle instanceof HTMLButtonElement) {
  const label = toggle.querySelector('[data-artifact-fullscreen-label]');
  const EXPANDED_CLASS = 'af-artifact-preview--expanded';

  // The button is server-rendered hidden so it only appears when this
  // progressive-enhancement module is present.
  toggle.hidden = false;

  const isExpanded = () => wrapper.classList.contains(EXPANDED_CLASS);

  const setExpanded = (expanded) => {
    wrapper.classList.toggle(EXPANDED_CLASS, expanded);
    document.body.classList.toggle('af-artifact-preview-locked', expanded);
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');

    if (label instanceof HTMLElement) {
      label.textContent = expanded ? 'Exit fullscreen' : 'Fullscreen';
    }
  };

  toggle.addEventListener('click', () => {
    setExpanded(!isExpanded());
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isExpanded()) {
      setExpanded(false);
    }
  });
}
