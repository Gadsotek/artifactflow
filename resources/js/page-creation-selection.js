const HTML_CREATION_MODES = new Set(['html_paste', 'html_upload']);

export function pageTypeForCreationMode(mode) {
  if (mode === 'markdown') {
    return 'markdown';
  }

  return mode === 'image_upload' ? 'image' : 'html_artifact';
}

export function creationModeForPageType(type, currentMode) {
  if (type === 'markdown') {
    return 'markdown';
  }

  if (type === 'image') {
    return 'image_upload';
  }

  return HTML_CREATION_MODES.has(currentMode) ? currentMode : 'html_paste';
}
