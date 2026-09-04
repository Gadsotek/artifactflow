-- ArtifactFlow artifact-host PostgreSQL grants.
-- Run as the database owner after creating the login role and replace
-- artifactflow_artifact_host with the deployment's actual role identifier.
-- Re-run and review this manifest whenever artifact-host query paths or schema
-- names change. This intentionally grants no schema creation or sequence use.
-- The role must be standalone: do not give it memberships in broader roles.

REVOKE ALL PRIVILEGES ON SCHEMA public FROM artifactflow_artifact_host;
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM artifactflow_artifact_host;
REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM artifactflow_artifact_host;
GRANT USAGE ON SCHEMA public TO artifactflow_artifact_host;

GRANT SELECT ON TABLE public.pages TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.page_versions TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.page_version_derivatives TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.xlsx_version_facts TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.docx_version_facts TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.external_shares TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.external_share_sessions TO artifactflow_artifact_host;
GRANT SELECT ON TABLE public.installation_settings TO artifactflow_artifact_host;

-- ResolveExternalShareView holds row locks through response materialization so
-- a concurrent revoke cannot commit and then receive stale artifact bytes.
-- PostgreSQL requires UPDATE on at least one column of every SELECT FOR UPDATE
-- table; limit that privilege to the non-authority updated_at timestamp.
GRANT UPDATE (updated_at) ON TABLE public.pages, public.external_shares, public.external_share_sessions TO artifactflow_artifact_host;

-- Required only when cache.limiter resolves to database_artifact_limiter on
-- this connection. The application limiter table is deliberately excluded:
-- control of artifact-host counters must not grant control of login, 2FA,
-- password-reset, MCP, or app-write counters.
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.artifact_rate_limit_cache TO artifactflow_artifact_host;
