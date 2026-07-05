let mermaidConfigured = false;
let diagramSequence = 0;

function safeSvg(svg) {
  const documentNode = new DOMParser().parseFromString(svg, 'image/svg+xml');

  if (documentNode.querySelector('parsererror')) {
    throw new Error('Mermaid returned invalid SVG.');
  }

  for (const element of documentNode.querySelectorAll(
    'script, foreignObject, iframe, object, embed, image',
  )) {
    element.remove();
  }

  for (const element of documentNode.querySelectorAll('*')) {
    for (const attribute of [...element.attributes]) {
      const attributeName = attribute.name.toLowerCase();
      const attributeValue = attribute.value.trim();

      if (attributeName.startsWith('on')) {
        element.removeAttribute(attribute.name);
        continue;
      }

      if (
        (attributeName === 'href' || attributeName === 'xlink:href' || attributeName === 'src') &&
        attributeValue !== '' &&
        !attributeValue.startsWith('#')
      ) {
        element.removeAttribute(attribute.name);
        continue;
      }

      if (attributeName === 'style' || attributeName === 'class') {
        element.removeAttribute(attribute.name);
      }
    }
  }

  for (const style of documentNode.querySelectorAll('style')) {
    style.remove();
  }

  const svgElement = documentNode.documentElement;
  svgElement.removeAttribute('width');
  svgElement.removeAttribute('height');
  svgElement.setAttribute('preserveAspectRatio', 'xMidYMid meet');
  svgElement.setAttribute('focusable', 'false');

  return svgElement.outerHTML;
}

function renderFailure(canvas) {
  const message = document.createElement('p');
  message.className = 'artifactflow-mermaid-error';
  message.textContent = 'This diagram could not be rendered. Open “Diagram source” to inspect it.';
  canvas.replaceChildren(message);
}

async function configuredMermaid() {
  const { default: mermaid } = await import('mermaid');

  if (!mermaidConfigured) {
    mermaid.initialize({
      startOnLoad: false,
      securityLevel: 'strict',
      suppressErrorRendering: true,
      htmlLabels: false,
      flowchart: {
        htmlLabels: false,
        useMaxWidth: true,
      },
      theme: document.documentElement.classList.contains('dark') ? 'dark' : 'neutral',
    });
    mermaidConfigured = true;
  }

  return mermaid;
}

export async function renderMermaidDiagrams(root = document) {
  const diagrams = root.querySelectorAll('[data-mermaid-diagram]:not([data-mermaid-rendered])');

  if (diagrams.length === 0) {
    return;
  }

  const mermaid = await configuredMermaid();

  for (const diagram of diagrams) {
    const canvas = diagram.querySelector('[data-mermaid-canvas]');
    const source = diagram.getAttribute('data-mermaid-source')?.trim();

    if (!(canvas instanceof HTMLElement) || !source) {
      continue;
    }

    diagram.setAttribute('data-mermaid-rendered', 'pending');

    try {
      diagramSequence += 1;
      const renderId = `artifactflow-mermaid-${diagramSequence}`;
      const { svg } = await mermaid.render(renderId, source);
      canvas.innerHTML = safeSvg(svg);
      diagram.setAttribute('data-mermaid-rendered', 'true');
    } catch {
      renderFailure(canvas);
      diagram.setAttribute('data-mermaid-rendered', 'error');
    }
  }
}
