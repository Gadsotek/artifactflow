const form = document.querySelector('[data-two-factor-challenge]');

if (form instanceof HTMLFormElement) {
  const authenticatorPanel = form.querySelector('[data-two-factor-authenticator-panel]');
  const authenticatorInput = form.querySelector('[data-two-factor-authenticator-input]');
  const recoveryPanel = form.querySelector('[data-two-factor-recovery-panel]');
  const recoveryInput = form.querySelector('[data-two-factor-recovery-input]');
  const rememberDevice = form.querySelector('[data-two-factor-remember-device]');
  const rememberDeviceInput = rememberDevice?.querySelector('input');
  const toggle = form.querySelector('[data-two-factor-mode-toggle]');

  if (
    authenticatorPanel instanceof HTMLElement &&
    authenticatorInput instanceof HTMLInputElement &&
    recoveryPanel instanceof HTMLElement &&
    recoveryInput instanceof HTMLInputElement &&
    rememberDevice instanceof HTMLElement &&
    rememberDeviceInput instanceof HTMLInputElement &&
    toggle instanceof HTMLButtonElement
  ) {
    let recoveryMode = false;

    const render = (focus = false) => {
      authenticatorPanel.hidden = recoveryMode;
      authenticatorInput.disabled = recoveryMode;
      authenticatorInput.required = !recoveryMode;
      recoveryPanel.hidden = !recoveryMode;
      recoveryInput.disabled = !recoveryMode;
      recoveryInput.required = recoveryMode;
      rememberDevice.hidden = recoveryMode;
      rememberDeviceInput.disabled = recoveryMode;
      toggle.setAttribute('aria-expanded', recoveryMode ? 'true' : 'false');
      toggle.textContent = recoveryMode ? 'Use an authenticator code' : 'Use a recovery code';

      if (focus) {
        (recoveryMode ? recoveryInput : authenticatorInput).focus();
      }
    };

    toggle.addEventListener('click', () => {
      recoveryMode = !recoveryMode;
      render(true);
    });

    render();
  }
}
