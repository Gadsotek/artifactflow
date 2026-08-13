<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\PageAccessGrant;

final readonly class DirectUserPageAccessGrantRevoker
{
    public function __construct(private PageAccessGrantRevocationJournal $journal)
    {
    }

    /**
     * @param list<string> $workspaceUids
     */
    public function forUserAcrossWorkspaces(
        string $userUid,
        array $workspaceUids,
        string $actorUid,
        string $summary,
        string $reason,
    ): int {
        if ($workspaceUids === []) {
            return 0;
        }

        $grants = PageAccessGrant::query()
            ->select('page_access_grants.*')
            ->join('pages', 'page_access_grants.page_uid', '=', 'pages.uid')
            ->whereIn('pages.workspace_uid', $workspaceUids)
            ->where('page_access_grants.subject_type', PageAccessSubjectType::User)
            ->where('page_access_grants.subject_uid', $userUid)
            ->orderBy('page_access_grants.uid')
            ->lockForUpdate()
            ->get();

        foreach ($grants as $grant) {
            $grantUid = $grant->uid;
            $pageUid = $grant->page_uid;
            $subjectType = $grant->subject_type;
            $subjectUid = $grant->subject_uid;
            $role = $grant->role;
            $grant->delete();

            $this->journal->record(
                pageUid: $pageUid,
                grantUid: $grantUid,
                subjectType: $subjectType,
                subjectUid: $subjectUid,
                role: $role,
                actorUid: $actorUid,
                summary: $summary,
                reason: $reason,
            );
        }

        return $grants->count();
    }
}
