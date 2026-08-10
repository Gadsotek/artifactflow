const HTML_CREATION_MODES = new Set(['html_paste', 'html_upload']);

export function creationModeForPageType(type, currentMode) {
  if (type === 'markdown') {
    return 'markdown';
  }

  if (type === 'image') {
    return 'image_upload';
  }

  return HTML_CREATION_MODES.has(currentMode) ? currentMode : 'html_upload';
}
