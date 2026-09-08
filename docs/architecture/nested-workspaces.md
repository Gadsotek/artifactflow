# Nested shared workspaces

Implemented and released in v0.0.9. This decision governs hierarchy and inherited
authority; it does not combine descendant content into a parent catalog.

## Product contract

```text
Company workspace
└── Department workspace
    └── Project workspace
```

| Rule | Behavior |
| --- | --- |
| Depth | At most three levels; no cycles or multiple parents |
| Workspace type | Shared only; personal workspaces remain standalone |
| Content | Every level holds its own pages, categories, storage counters, Library, and page tree |
| Membership | Parent roles flow downward by default, never upward or sideways |
| Inheritance boundary | A child may opt out at creation; a child Admin may exclude a specific inherited user |
| Direct roles | Independently effective at the child or below, including at an exclusion boundary |
| Administration | Every shared workspace retains a direct Admin |
| Deletion | A workspace with children cannot be deleted; no cascading hierarchy deletion |
| MCP scope | Selected workspace UIDs stay exact; only all-workspaces tokens follow future live reach |

The effective role is the strongest surviving direct/inherited role. The
resolver walks target to root, considers each boundary's direct membership
first, then blocks higher origins if inheritance is disabled or the user is
excluded. An exclusion can coexist with a lower direct role without reviving
a stronger ancestor role.

## Persistence

`workspaces.parent_workspace_uid` is the authoritative direct parent with
restrictive deletion. `inherits_parent_memberships` defaults true; root
workspaces must store true. Promoting an opted-out child resets it to true,
so later attachment uses the documented default.

`workspace_membership_exclusions` stores one workspace/user exclusion and its
actor. It blocks only origins above that boundary, persists across reparenting,
and is not a copied membership.

`workspace_ancestry` is a derived closure index, keyed by ancestor/descendant,
with depth `0..2`. Depth zero is exactly the self row. Every workspace has a
self row; personal workspaces have no other ancestry. Descendant/depth and
ancestor/depth indexes support resolution and subtree queries. Supported writes
update parent and closure rows together. Migration leaves existing workspaces
as roots and infers no hierarchy from names or pages.

## Mutation and locking

Hierarchy creation/reparenting, membership changes, invitations, page creation,
page moves, and workspace-subject grant writes share one transaction-scoped
PostgreSQL hierarchy advisory lock before ancestry-dependent authorization.
Ordinary reads and content updates do not take it.

After the hierarchy lock, commands resolve the complete affected subtree and
proposed ancestry, reject cycles/depth/personal-workspace violations, and
resolve before/after authority. They lock page rows in ascending UID order
before workspace rows, reauthorize, enforce owners/admins, and commit parent,
closure, invalidation, audit, and event changes together.

This deliberately serializes rare security-boundary rearrangements. Direct
database edits can violate these invariants and are not a supported mutation path.

## Membership and invitations

All access consumers use the effective-membership resolver: policies,
`WorkspaceAccess`, `PageAccess`, search, taxonomy, grants, owners, invitations,
preview issuance, realtime, and MCP. Coarse SQL narrowing never replaces the
exact authorization check.

Member UI distinguishes direct and inherited roles and names their authorized
origin. An inherited Admin can manage the child. Child creation still gives
the creator direct Admin membership. System Admin remains separate from
content authority.

Invitations target one exact workspace and create a direct membership. On
acceptance, an equal/weaker invitation is redundant if stronger inherited
authority survives. With an exclusion, a direct role may establish an exact
lower role. Removing a direct row removes only that row's contribution.

Removal, downgrade, exclusion, or reparenting revokes reusable invitations
where effective authority fell. Where membership is fully lost, it revokes
direct page grants and records removal timestamps. Legacy grants created at
or before the latest removal remain invalid even after membership returns;
a fresh post-reacquisition grant may apply.

## Ownership, grants, and settings

Page owners need effective Editor/Admin authority in the exact page workspace.
Mutations losing that authority require an explicit eligible replacement for
each affected owned page. The system does not silently choose an owner.

Workspace-subject page grants use the subject workspace's effective members:
a child grant includes inherited parent members; a parent grant excludes
child-only members. Grant role is capped by the subject member's effective
role. MCP must include the page and grant-subject workspaces wherever required
by the existing exact-scope calculation.

`allow_editor_invites` and `allow_editor_page_sharing` compose with logical AND
across the ancestry chain. A child can be stricter, never looser. UI shows
local and inherited state. New authority-affecting settings must define their
composition here before shipping; other settings remain local unless stated.

## Reparenting

Attaching a root, moving a subtree, or promoting a child requires effective
Admin on the moved workspace and both old/new parents where present.

The browser presents bounded counts of moved workspaces, affected pages,
gaining users, and losing users. Its target/session-bound confirmation lasts
at most ten minutes. The command recalculates those counts under the hierarchy
lock and rejects a changed impact rather than using stale approval.

Reject personal endpoints, self/descendant destinations, fourth levels,
loss of required administrators, or owner access loss without reassignment.
Every preview revision in the moved subtree is invalidated, not only the
computed loss set. After commit, presence revocation targets actual view loss.

## Revocation and disclosure

In the same transaction, authority changes bump affected preview revisions,
invalidate grants elsewhere that depended on changed workspace membership,
update removal journals, and record non-secret audit/events. After commit,
`PagePresenceRevoker` notifies lost viewers.

Already-delivered bytes cannot be erased. A non-cooperative existing Reverb
subscriber may retain bounded presence identity metadata until its socket
closes; it cannot resubscribe or fetch content after revocation.

Navigation/management/search reveal no inaccessible title, UID, placeholder,
count, breadcrumb gap, taxonomy row, or facet. An ancestor path explaining
inherited authority is visible only where that user inherits from it.
Event metadata contains IDs, depth, and bounded impact counts, never names,
emails, member lists, content, token scopes, preview URLs, or share secrets.

## MCP

```text
exact selected workspace scope
  intersect live effective membership/page reach
  intersect operation scope and Editor ceiling
```

A parent-scoped token cannot discover or use a child solely through inherited
membership. A child-scoped token may use inherited authority in that selected
child. `workspace_uids = null` follows all current/future live reach.
No workspace creation, hierarchy, settings, invitation, or membership tools
are added to MCP.

## Required evidence

Keep tests for root backfill, personal-workspace exclusion, concurrent cycles
and depth, direct/inherited/excluded role resolution, descendant revocation,
owner/direct-Admin continuity, grant direction, hidden metadata, exact MCP
scope, and exact-workspace storage/catalog behavior. Browser evidence covers
tree navigation, origin labels, and real saved-preview revocation.

Rejected alternatives are unlimited depth, copied inherited memberships,
parent Library rollups, and implicit descendant token scopes. They complicate
or silently expand authority. Local exclusions are supported; a general deny
precedence/RBAC policy engine is not.
