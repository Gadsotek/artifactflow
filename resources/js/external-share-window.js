const tokenPrefix = 'artifactflow.external-share.window.';

function storageKey(selector) {
  return `${tokenPrefix}${selector}`;
}

export function storeExternalShareWindowToken(selector, token) {
  if (!/^[0-9A-Za-z]{26}$/u.test(selector) || !/^[a-f0-9]{64}$/u.test(token)) {
    return false;
  }

  try {
    window.sessionStorage.setItem(storageKey(selector), token);

    return true;
  } catch {
    return false;
  }
}

export function externalShareWindowToken(selector) {
  if (!/^[0-9A-Za-z]{26}$/u.test(selector)) {
    return null;
  }

  try {
    const token = window.sessionStorage.getItem(storageKey(selector));

    return typeof token === 'string' && /^[a-f0-9]{64}$/u.test(token) ? token : null;
  } catch {
    return null;
  }
}
