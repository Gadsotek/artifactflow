# ArtifactFlow Desktop Extension Privacy Policy

Revision: 22 September 2026. Publisher: Gadsotek.

This notice covers the ArtifactFlow extension for Claude Desktop distributed
by Gadsotek. It explains the connector's data handling. Your organization
operates the ArtifactFlow server you configure and determines how that server
stores and uses information. Ask that operator for its own privacy notice,
retention rules, and contact details. Anthropic's terms and privacy policies
separately govern Claude Desktop and your Claude account.

## Data handled

You provide your ArtifactFlow app URL and a personal MCP access token. The
extension uses them to connect and authenticate to that server. The token
grants only its configured operations and workspaces, subject to your current
ArtifactFlow permissions. It is not bundled with the software and is not
included in tool results by the connector.

When Claude calls an ArtifactFlow tool, the connector forwards the requested
tool name and arguments to your server and returns its response to Claude.
Depending on the requested operation, this can include search terms, page and
workspace identifiers, titles, descriptions, tags, author/provenance metadata,
artifact content, images, document text, bounded spreadsheet selections,
uploads, or a newly created external-share link. Tool responses can contain
private organizational information. Enable only the operations and workspaces
you intend to make available in Claude.

The connection also exposes normal network metadata, such as the connecting
computer's IP address, to the server and applicable network intermediaries.
The connector does not collect your full Claude conversation automatically or
scan your computer for documents. Content explicitly supplied as tool arguments
is transmitted as part of that request.

## Use of data

The connector uses connection settings for authentication and carries tool
requests and results between Claude Desktop and the configured ArtifactFlow
installation. Requested writes can create or change persistent artifacts,
metadata, versions, and audit records on that installation. External sharing
requires its separate token capability and server authorization.

The connector adds no advertising, analytics, or publisher telemetry endpoint.
Installing it does not send your artifacts to Gadsotek. If Gadsotek is also
your server operator, that operator's separate server notice applies.

## Storage and retention

Claude Desktop manages the URL and the token as extension settings; the token
is marked sensitive for the client's secure-settings mechanism. The launcher
passes the token to its bridge process through the process environment, not
command-line arguments. Settings remain subject to the client's storage,
backup, removal, and organizational management behavior.

The launcher does not intentionally save tool request or response bodies to
local files and suppresses upstream bridge diagnostics. Its own diagnostics
use fixed configuration or connection errors. The bundled `mcp-remote` library
contains upstream authentication/discovery support and can maintain local
authentication state in its `.mcp-auth` cache if that support is invoked by a
server challenge. This extension's supported ArtifactFlow configuration uses
your personal bearer token; it does not configure an OAuth sign-in service.

Artifacts, versions, audit records, and operational logs held by your server
follow that installation's settings and retention policies. Tool results and
conversation content held by Claude follow your Anthropic account terms,
settings, and retention rules. There is no single connector-defined retention
period for these independent systems.

To stop future access, revoke the token in ArtifactFlow's **AI connections**
and disable or uninstall the extension. Remove saved connection settings and
any remaining bridge authentication cache according to your administrator's
and client's instructions. Revoking a token does not delete existing server
records, backups, or content already returned to a Claude conversation.

## Third parties

Your configured ArtifactFlow operator and its infrastructure providers process
requests sent to that installation. Anthropic receives tool results through
Claude; consult the privacy terms applicable to your individual or business
account. The bundled open-source bridge runs locally. Its software authors do
not receive tool content merely because their library is included.

The supported remote connection uses HTTPS. Your selected server, its
authentication responses, and your network/proxy configuration determine the
network services contacted. Local development permits explicit loopback HTTP.
No shared company credential or Gadsotek-hosted relay is supplied in the bundle.

## Contact

For this extension's privacy practices, contact
[Gadsotek](mailto:gadsotek@gmail.com). Contact your ArtifactFlow administrator
for access, deletion, retention, or incident questions about your installation,
and Anthropic for data held by Claude.

Report software issues through the
[ArtifactFlow issue tracker](https://github.com/Gadsotek/artifactflow/issues).
Do not post access tokens, private artifacts, or sensitive logs there. Use the
[security reporting instructions](https://github.com/Gadsotek/artifactflow/blob/main/SECURITY.md)
for suspected vulnerabilities.
