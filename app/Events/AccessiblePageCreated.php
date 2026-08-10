<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class AccessiblePageCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    /**
     * @param list<string> $recipientUserUids
     */
    public function __construct(
        private string $pageUid,
        private string $workspaceUid,
        private array $recipientUserUids,
    ) {
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $userUid): PrivateChannel => new PrivateChannel(
                'user.' . $userUid . '.page-catalog',
            ),
            $this->recipientUserUids,
        );
    }

    public function broadcastAs(): string
    {
        return 'page.created';
    }

    /** @return array{page_uid: string, workspace_uid: string} */
    public function broadcastWith(): array
    {
        return [
            'page_uid' => $this->pageUid,
            'workspace_uid' => $this->workspaceUid,
        ];
    }
}
