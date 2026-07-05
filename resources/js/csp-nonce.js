export function appCspNonce() {
  for (const script of document.scripts) {
    const nonce = script.nonce ?? '';

    if (/^[A-Za-z0-9+/_=-]+$/u.test(nonce)) {
      return nonce;
    }
  }

  return '';
}
