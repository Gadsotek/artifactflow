# Security model

Treat artifacts as untrusted, even when someone you trust created them.

| Surface | Main boundary |
| --- | --- |
| Executable HTML | Separate cookieless host, opaque scripts-only iframe, restrictive HTTP header CSP |
| Markdown/Mermaid | Sanitization, strict diagram rendering, app CSP |
| Images | Dedicated decoder, only normalized pixels retained, scriptless preview |
| XLSX | Isolated typed projection; browser never parses the workbook |
| PDF/DOCX | Isolated validation/conversion, artifact-origin native PDF viewing |
| People and MCP | Live page/workspace authorization, scoped tokens, concurrency checks |
| External links | One-page expiring/one-time capabilities and separate window-lived viewer proof |

Server rewriting and an early browser guard enforce the supported HTML profile,
including no nested browsing contexts. They supplement the browser controls.
The two origins must use different hostnames because cookies ignore ports.

**Residuals:** HTML self-navigation can send embedded or user-entered data
externally. WebRTC blocking is browser-dependent. Parser/kernel flaws remain
possible. Prompt injection is not solved. Revocation cannot erase delivered
content. PDF/DOCX-PDF viewing is download-equivalent and uses the documented
PDF-only sandbox exception.

PDF, XLSX, and DOCX remain default-off pending deployment-specific verification.
System Admin is not a content superuser. Scanning is advisory, not a security
certificate. The project has no independent third-party audit.

Read the [threat model](https://github.com/Gadsotek/artifactflow/blob/main/THREAT-MODEL.md)
for exact controls, references, and risks. Report vulnerabilities privately
through the [security policy](https://github.com/Gadsotek/artifactflow/blob/main/SECURITY.md).
