# MCP read provenance response-size fixture

This is a non-gating measurement for the representative compact-provenance unit fixture. The
fixture has one partial AI producer occupying all three lineage roles: page origin, direct version,
and effective content origin. JSON uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, matching
the MCP adapter.

| Shape | JSON bytes |
| --- | ---: |
| Legacy repeated producer blocks | 2,122 |
| Compact producer catalog and UID references | 975 |

The compact shape saves 1,147 bytes (54.1%) for this fixture. These values document the change;
they are deliberately not a brittle CI threshold.
