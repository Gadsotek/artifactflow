# MCP provenance

[MCP setup](mcp.md)

The identities below are illustrative declarations, not a list of available models.

Content-version write tools may include provenance when the caller knows any safe producer fact. Do
not make the object mandatory in client wrappers, do not discard a known provider or model family
merely because the exact provider model ID is unavailable, and do not fill missing fields by guessing from the MCP client
name. `clientInfo.name=claude-code`, for example, is unverified caller-reported protocol metadata:
it does not prove which implementation submitted the request, nor that Claude, a particular Opus
release, or even an AI model authored the content.

ArtifactFlow rejects non-string nested `clientInfo` fields during initialization and retains only
the newest 64 client-reported transport sessions per MCP access token. Initialization serializes this
per-token pruning so concurrent clients cannot bypass the cap. Evicting an older observation removes
only client-name/version attribution for that transport session; it does not revoke the access token
or turn the transport session identifier into authority.

An exact AI declaration may include both `provider` and the provider-defined `model_id`:

```json
{
  "provenance": {
    "producers": [{
      "kind": "ai",
      "provider": "anthropic",
      "model_id": "example-model-id",
      "model_label": "Example model",
      "model_version": "example-version",
      "generated_at": "2026-08-01T13:42:00.123Z",
      "references": [{
        "kind": "conversation",
        "ref": "abc123",
        "url": "https://claude.ai/chat/abc123"
      }]
    }]
  }
}
```

A partial declaration is equally valid when that is all the caller can support:

```json
{
  "provenance": {
    "producers": [{
      "kind": "ai",
      "provider": "OpenAI",
      "model_label": "Known model family",
      "extensions": [{
        "key": "openai.runtime_product",
        "value": "Codex"
      }]
    }]
  }
}
```

ArtifactFlow preserves the reported provider value, derives a normalized provider key for search,
and reports this claim as `partial`; it does not require or synthesize `model_id`. Successful MCP
content writes return `stored_provenance` with `supplied`, computed completeness, identity precision,
and the direct producer fields that were actually retained. MCP server instructions require the
caller to summarize that receipt to the requesting user.

MCP claims are stored as `self_reported`; the caller cannot select stronger evidence. Every retained
provenance string is scanned for the same obvious credential patterns that block artifact writes.
Extensions are limited to 16 lowercase namespaced key/string-value pairs. They are for short producer
identity metadata only; prompt or chain-of-thought material, credentials, authorization data, signed
URLs, and content/blob payloads are rejected rather than treated as provenance.
External references are optional, HTTPS-only, never fetched, and should not contain signed URLs,
prompt content, or personal data that does not belong in the page's authorization boundary. They
are returned only to principals who can read the page and are excluded from logs, events, audit
metadata, and search. Producer identifiers are also excluded from event and audit metadata.

Authorized searches accept `ai_provider`, `ai_model_query`, and `provenance_scope`:

- `page_origin` matches version one;
- `current_version` matches only the current version's direct producer;
- `any_version` also matches historical and content-pruned version provenance.

The page full-text vector includes at most 256 deterministic, deduplicated provider/model-label
pairs so retained history cannot exceed PostgreSQL's `tsvector` limit. The structured filters above
remain exhaustive across all retained ingests.

The `read` result distinguishes the current ingest actor/client from direct content producers and
effective byte origin. A restore therefore identifies who performed the restore without relabeling
that person/client as the model that produced the restored bytes. Each producer is defined once in
the top-level provenance `producers` catalog; page origin, direct version, and effective content
origin contain ordered producer-UID references. Optional descriptions, current change summaries,
producer identity fields, MCP-reported client fields, and reference values are absent when unknown;
every value that is present retains its complete field-level untrusted-data envelope.
