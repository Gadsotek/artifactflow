# Typed XLSX viewer spike

This spike renders only the canonical `xlsx-typed-view-v1` manifest. It never
receives or parses XLSX bytes. Tabulator `6.5.0` is locally installed and only
the format, interaction, keybinding, and resize-table modules are registered.
Editing, import, export, AJAX, clipboard, persistence, calculation, sorting,
filtering, and formula engines are absent.

The spike imports Tabulator's unminified ESM distribution so esbuild can remove
unregistered modules. The minified IIFE is about 160 KB and the build contract
rejects dormant AJAX, clipboard, download, edit, HTML-import, persistence, and
spreadsheet modules plus `fetch` and XHR. A classic local script is deliberate:
module scripts do not load reliably in the required opaque-origin iframe.

Build and serve it from the repository root:

```sh
npm --prefix xlsx-viewer-spike test
python3 -m http.server 4177 --bind 127.0.0.1 --directory xlsx-viewer-spike
```

Open `http://127.0.0.1:4177/` for the hostile-string, formula, external-link,
and internal-link fixture. Add `?stress=1` for a 20,000-row virtual-rendering
fixture.

The page has a deny-by-default CSP, local scripts and styles only, no connect,
image, object, media, form, or frame sources, and no inline script. Framing and
sandbox policy must be delivered as HTTP headers and iframe attributes; the
document does not pretend that unsupported CSP meta directives enforce them.
Cell values, formula text, and sheet names enter the DOM through `textContent`;
typed links are independently checked before an application-owned anchor or
button is created.
