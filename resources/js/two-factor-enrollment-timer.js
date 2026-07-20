const timer = document.querySelector('[data-two-factor-enrollment-timer]');
const remaining = timer?.querySelector('[data-two-factor-enrollment-remaining]');
const expiryMessage = timer?.querySelector('[data-two-factor-enrollment-expiry-message]');
const deadline = Number(timer?.dataset.twoFactorEnrollmentDeadline ?? Number.NaN);
const expiredUrl = timer?.dataset.twoFactorEnrollmentExpiredUrl;

if (
  timer instanceof HTMLElement &&
  remaining instanceof HTMLElement &&
  Number.isSafeInteger(deadline)
) {
  const render = () => {
    const seconds = Math.max(0, deadline - Math.floor(Date.now() / 1000));
    const minutes = Math.floor(seconds / 60);

    remaining.textContent = `${minutes}:${String(seconds % 60).padStart(2, '0')}`;

    if (seconds === 0) {
      timer.dataset.twoFactorEnrollmentExpired = 'true';
      if (expiryMessage instanceof HTMLElement) {
        expiryMessage.textContent =
          'The enrollment window expired. Confirm your password, then start again for a new QR code.';
      }

      if (typeof expiredUrl === 'string' && expiredUrl !== '') {
        window.location.assign(expiredUrl);
      }

      return false;
    }

    return true;
  };

  if (render()) {
    const interval = window.setInterval(() => {
      if (!render()) {
        window.clearInterval(interval);
      }
    }, 250);
  }
}
