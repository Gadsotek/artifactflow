# Nested Shared Workspaces

Status: Accepted for implementation
Date: 2026-08-11

## Context

ArtifactFlow currently treats every personal or shared workspace as a flat,
independent permission boundary. Pages may form a hierarchy inside one
workspace, but workspace membership, categories, storage accounting, Library
filters, realtime authorization, and MCP token scopes all resolve against one
exact workspace UID.

Internal teams need a small amount of hierarchy for structures such as a
company workspace containing department workspaces and project workspaces.
Making the parent a security principal rather than a cosmetic folder means a
parent member must receive predictable authority in every descendant, and a
parent removal or role downgrade must revoke that authority everywhere it was
inherited.

This decision introduces a deliberately bounded hierarchy. It does not turn
ArtifactFlow into an organization/RBAC platform and does not merge descendant
content into a parent catalog.

## Decision

Shared workspaces may form a tree with at most three levels total:

```text
Root workspace
└── Child workspace
    └── Grandchild workspace
```

The following product invariants apply:

1. Personal workspaces are always standalone and cannot be a parent or child.
2. Every shared workspace remains an ordinary page-bearing workspace.
3. A workspace has at most one direct parent.
4. A hierarchy has at most two parent edges: root, child, grandchild.
5. A workspace cannot become its own ancestor.
6. Selecting a workspace shows only that workspace's pages. Descendant pages
   do not appear in its Library, search filter, category list, storage counter,
   or page tree.
7. Parent membership flows downward with the same role by default. A child may
   opt out when it is created, in which case memberships originating above that
   child do not cross the boundary.
8. A child Admin may exclude one inherited user at that child. The exclusion
   also blocks that user's ancestor-derived access in descendants, while an
   independent direct membership at the child or below remains effective.
9. Effective authority is the strongest accepted direct or inherited role
   whose path is not blocked. A direct membership at an exclusion boundary is
   evaluated before the exclusion blocks memberships originating above it.
10. Child-only members gain no parent or sibling authority.
11. A selected MCP workspace scope is exact. Scoping a token to a parent does
    not add its current or future descendants. An all-workspaces token follows
    the principal's live effective reach.
12. A workspace with children cannot be deleted. Workspace deletion remains
    outside the first implementation slice, but the invariant is fixed now so
    no future delete path can cascade through a hierarchy.

Hierarchy and authority are server-side invariants. Tree indentation, hidden
buttons, and disabled form controls are not access controls.

## Persistence

`workspaces` gains a nullable `parent_workspace_uid` self-reference. It is the
authoritative direct-parent relationship and uses restrictive deletion
semantics. It also gains `inherits_parent_memberships`, a boolean that defaults
to true. Root workspaces store true because the setting has no parent boundary
to affect; both the database and hierarchy writer enforce this. Promoting an
opted-out child to a root resets the flag to true, so a later attachment starts
with the documented default instead of silently reviving an obsolete barrier.

`workspace_membership_exclusions` stores one local inherited-access exclusion
per workspace/user pair, including the administrator who created it. These are
authority records, not copied memberships: they block only membership origins
above their workspace boundary and remain effective after reparenting. A
direct membership at the same workspace coexists with the exclusion so an
administrator can deliberately grant an exact local role without reviving a
stronger ancestor role.

`workspace_ancestry` is an indexed closure table containing:

- `ancestor_workspace_uid`;
- `descendant_workspace_uid`;
- `depth`, where `0` is the workspace itself, `1` is its parent, and `2` is its
  grandparent.

The primary key is the ancestor/descendant pair. Separate descendant/depth and
ancestor/depth indexes support effective-membership and subtree queries. Check
constraints require depth to remain between zero and two and require depth
zero exactly for self rows. Every workspace, including a personal workspace,
has one self row; personal workspaces have no other ancestry rows.

The closure table is a derived authorization index, not a second source of
hierarchy truth. The parent column defines the tree. Hierarchy commands rebuild
the moved subtree's closure rows transactionally and tests assert that the two
representations cannot drift through supported writes.

The migration backfills one depth-zero row for every existing workspace and
leaves every existing workspace at the root. It does not infer relationships
from names, page parents, memberships, or categories.

## Serialized hierarchy writes

Hierarchy creation and reparenting are rare, security-sensitive writes. Every
such command takes one transaction-scoped PostgreSQL advisory lock dedicated
to the workspace hierarchy before reading ancestry. Serializing hierarchy
writes makes cycle and depth checks deterministic under concurrent requests
without relying on a stale pre-lock tree snapshot.

After taking the hierarchy lock, a command:

1. resolves the complete moved subtree and proposed ancestor chain;
2. rejects personal workspaces, cycles, and a resulting depth greater than two;
3. resolves authority against the locked hierarchy, applying inheritance
   opt-outs and per-user exclusions to both the live and proposed ancestry;
4. locks affected page rows in ascending UID order before workspace rows, in
   the existing page-to-workspace order;
5. rechecks ownership and administrator invariants;
6. updates the direct parent and closure rows;
7. invalidates affected preview access revisions and records the change in the
   audit/event journal in the same transaction.

The global hierarchy lock is shared by hierarchy and membership mutations,
invitation creation/registration/acceptance, page creation, page moves between
workspaces, and workspace-subject page-grant writes. These placement and
authority writes must not commit from authorization evaluated against a tree
that a concurrent reparent has already changed. Ordinary page reads and content
updates do not take the lock. This is a deliberate throughput trade-off: teams
rarely rearrange workspace security boundaries, while concurrent correctness
is mandatory.

## Effective membership

`workspace_memberships` continues to store direct accepted memberships only.
Inherited memberships are never copied into descendant membership rows.

A centralized effective-membership resolver returns:

- the strongest effective `Reader`, `Editor`, or `Admin` role;
- every direct membership that contributed to that result;
- the workspace where each membership originates;
- whether the winning role is direct, inherited, or tied across both.

For a target workspace, the resolver walks the target-to-root ancestry path.
At each boundary it first considers a direct membership at that boundary, then
stops considering higher origins for that user if the workspace disabled
parent inheritance or contains a local user exclusion. It never considers
descendants or siblings. Role rank remains `Admin > Editor > Reader` among the
origins that survive those boundary checks.

All consumers must delegate to this resolver rather than querying
`workspace_memberships` as an authorization decision. This includes:

- `WorkspaceAccess` and `PageAccess`;
- workspace policies and current-workspace navigation;
- page creation, ownership, grants, and workspace moves;
- Library/search visibility and taxonomy discovery;
- invitations and member management;
- preview URL issuance and access-revision invalidation;
- Reverb channel authorization and presence revocation;
- MCP workspace listing, search, read, and writes.

Coarse SQL queries may use the ancestry table to narrow candidates, but the
existing exact application authorization remains the final check.

## Direct and inherited member management

The member screen distinguishes:

- **Direct members**, managed in the selected workspace.
- **Inherited members**, labelled with the ancestor workspace where their role
  originates. A child Admin may remove that inherited access locally without
  changing the originating membership.

An inherited Admin is an effective Admin of the child and may administer it.
An inherited Editor or Reader has the same content capabilities in the child
as an equal direct role. System Admin remains unrelated to content authority.

Invitations always target one exact workspace and create a direct membership
there. Without a local exclusion, a pending invitation cannot reduce authority
already inherited at acceptance time. The acceptance handler re-resolves live
authority; an equal or weaker invitation is rejected as redundant, while a
stronger role creates the direct elevation. With a local exclusion, a direct
membership may establish an exact lower local role because the stronger
ancestor origin remains blocked. Membership removal, role downgrade, inherited
exclusion, and reparenting revoke reusable invitation rows for each descendant workspace where
the invitee's live effective authority was reduced, so a previously accepted or
expired invitation cannot later be reactivated to restore superseded access.

Every shared workspace retains at least one direct Admin. Creating a child
therefore keeps the creator's direct Admin membership even when the creator is
already an inherited Admin. This preserves an accountable manager if the
workspace is later moved or promoted to a root.

Removing or downgrading a direct membership changes only authority contributed
by that row. If another direct or inherited membership still supplies an equal
or stronger role, the actor keeps that effective authority.

Excluding an inherited member is an authority-reduction transaction, not a UI
filter. The handler reauthorizes the Admin under the hierarchy lock, computes
before/after authority for the selected subtree, requires explicit replacement
for owned pages where write access would be lost, revokes stale invitations and
direct page grants, records removal timestamps, bumps preview revisions, and
revokes lost realtime presence. The exclusion remains when a direct local
membership is later added so that the direct role does not accidentally revive
a stronger ancestor role.

## Page ownership and grants

Page ownership still requires effective Editor or Admin authority in the
page's exact workspace.

Before a membership downgrade, removal, or hierarchy change commits, the
handler calculates which users would lose effective write authority in every
affected workspace. If such a user owns pages there, the mutation is blocked
until those pages have an explicitly eligible replacement owner. ArtifactFlow
does not silently choose an administrator or move ownership across workspace
boundaries.

A page grant whose subject is a workspace applies to that workspace's
effective members. Consequently:

- granting a page to a child includes members inherited by that child;
- granting a page to a parent does not include child-only members;
- the granted role is still capped by the subject member's effective role;
- an exact MCP token scope must include both the page's workspace and the
  workspace grant subject wherever the existing grant calculation requires
  them.

Direct user page grants keep the existing removal-journal safety rule. A user
who loses inherited membership in a descendant is treated as removed from
that effective workspace unless another direct or inherited path remains.
The hierarchy mutation atomically revokes direct user grants in every workspace
where effective membership is fully lost and records the normal durable grant
revocation event and audit entry. The removal timestamp is also checked for
every surviving legacy grant even after membership later returns, so a grant
created at or before the latest removal cannot silently recover stronger
historical authority. A fresh post-reacquisition grant remains valid.

## Workspace settings

Role-affecting boolean settings use restrictive composition. For
`allow_editor_invites` and `allow_editor_page_sharing`, the effective value is
the logical AND of the selected workspace's local value and every ancestor's
local value.

A child can therefore be stricter than its parent but cannot loosen an
ancestor restriction. A local `true` means "allow unless an ancestor blocks";
the UI shows both the local value and any inherited restriction. Changing an
ancestor setting re-evaluates descendants immediately and triggers the same
access-revision invalidation required by the affected capability.

New role-affecting workspace settings must define their hierarchy composition
in this decision before they ship. Settings that do not affect authority or
sharing remain local unless explicitly documented otherwise.

## Reparenting

Reparenting includes attaching a root below a parent, moving a subtree between
parents, and promoting a child to a root. It requires effective Admin authority
over:

- the moved workspace;
- its old parent, when present;
- its new parent, when present.

The command presents a non-secret reach summary before confirmation: moved
workspace count, affected page count, users gaining effective reach, and users
losing effective reach. User-controlled workspace names do not enter audit or
domain-event metadata. The browser flow stores a target-bound, session-bound
preview for at most ten minutes and requires a second explicit confirmation.
The command recalculates the impact under the hierarchy lock and rejects the
confirmation if any displayed count changed before the transaction commits.

The transaction rejects a move when:

- either side is personal;
- the destination is the workspace itself or inside its subtree;
- any moved descendant would exceed grandchild depth;
- an affected workspace would lose its last effective Admin;
- a page owner would lose effective write authority without explicit
  reassignment.

Reparenting invalidates every page preview revision in the moved subtree. This
is intentionally broader than the exact loss set: saved preview URLs are cheap
to renew, while retaining a URL minted under a previous hierarchy is an
unnecessary authorization ambiguity. After commit, presence revocation targets
only users and pages where live view authority was actually lost.

## MCP scope semantics

The token's workspace selection is checked against the requested target UID
before inherited membership is resolved:

```text
effective MCP reach
  = exact token workspace ceiling
  ∩ live effective human/service-account workspace reach
  ∩ MCP's existing Admin-to-Editor capability ceiling
```

A token scoped to a parent cannot list, search, read, or mutate a child merely
because its principal inherits access there. A token scoped to a child may use
authority inherited from the parent inside that child. Adding or reparenting a
descendant never expands a selected token's UID list. `workspace_uids = null`
continues to mean all workspaces the principal can reach live, including new
descendants.

MCP never gains hierarchy-administration tools in this slice. Workspace
creation, hierarchy changes, settings, invitations, and memberships remain
browser-only administrative operations.

## Revocation, previews, and realtime

Membership removal, role downgrade, restrictive ancestor-setting changes, and
reparenting calculate the affected subtree while the hierarchy is stable.
Inside the same transaction they:

1. bump `preview_access_revision` for pages whose saved preview authority may
   have changed;
2. invalidate pages elsewhere whose workspace grants no longer apply;
3. update removal-journal state for effective workspace losses;
4. persist non-secret audit entries and durable domain events.

After commit, `PagePresenceRevoker` emits revocation notices for pages where
the affected user lost live view authority. The existing residual remains: a
non-cooperative client may retain already-subscribed presence identity metadata
until its socket closes, but cannot fetch content or re-subscribe.

Already-delivered page or artifact bytes cannot be remotely erased. Revision
invalidation closes future preview loads and renewal.

## Audit and disclosure

Hierarchy creation and reparenting record non-secret events and audit entries
with workspace UIDs, actor UID, old/new parent UIDs, depth, and bounded counts
of affected workspaces, pages, gains, losses, and invalidated previews.

Workspace names, page titles, member emails, raw membership lists, page
content, MCP token scopes, preview URLs, and external-share secrets do not
belong in hierarchy event metadata.

Navigation and management queries reveal only workspaces the actor can reach.
An inaccessible parent, child, or sibling produces no placeholder, UID, title,
count, breadcrumb gap, taxonomy row, or search facet. A child member may see
the ancestor path required to explain inherited authority only for ancestors
from which that user actually inherits membership.

## Required proof before release

1. Existing flat workspaces backfill as roots without authority changes.
2. Personal workspaces cannot enter a hierarchy.
3. Cycles and a fourth level fail under ordinary and concurrent writes.
4. Direct and inherited roles resolve to the strongest role without deny or
   downgrade behavior.
5. Parent removal and downgrade revoke lost descendant access, preview URLs,
   workspace grants, and new realtime subscriptions immediately.
6. Direct child membership preserves only the authority it independently
   grants after an ancestor membership changes.
7. Every affected workspace retains a direct Admin and every page retains an
   effective Editor/Admin owner.
8. Parent and child workspace grants apply in the correct direction without
   leaking restricted titles, UIDs, counts, taxonomy, or hierarchy gaps.
9. Selected MCP scopes remain exact through child creation and reparenting;
   all-workspaces tokens follow live reach and remain Editor-capped.
10. Categories, storage counters, page hierarchy, page moves, and Library
    filters remain exact-workspace operations.
11. Browser tests cover tree navigation, member-origin labels, and saved HTML
    preview revocation through the real application and artifact-host flow.

## Rejected alternatives

### Cosmetic workspace folders

Folders would avoid inherited authorization, but they would not satisfy the
team expectation that parent membership grants child access. Calling such a
folder a parent workspace would be misleading.

### Unlimited depth

Unlimited trees make authorization explanations, subtree invalidation,
navigation, and cycle-safe mutation materially harder without a demonstrated
internal-team need. Three levels cover organization, department, and project.

### Copy inherited memberships into every child

Materialized copies obscure where authority originates, create fan-out writes,
and can leave stale access after a failed or partial revoke. The ancestry index
and direct membership rows are sufficient to resolve live authority.

### Parent Library rolls up descendant pages

A rollup conflates otherwise separate categories, storage counters, page
hierarchies, filters, and MCP scopes. A later cross-workspace discovery view may
offer an explicit descendant filter, but selecting a workspace remains exact.

### Deny records or child downgrades

Deny precedence would make a user's effective role harder to explain and would
turn reparenting into a policy-conflict migration. The first version is
monotonic: hierarchy can only add authority downward.

### Implicit descendant MCP scopes

Automatically expanding a parent-scoped token would let later child creation
or reparenting silently widen a standing credential. Selected scopes therefore
remain an immutable list of exact workspace UIDs.
