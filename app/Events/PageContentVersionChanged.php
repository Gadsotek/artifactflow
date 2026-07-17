<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PageContentVersionChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    public function __construct(
        private string $pageUid,
        private string $versionUid,
        private int $versionNumber,
    ) {
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('page.' . $this->pageUid);
    }

    public function broadcastAs(): string
    {
        return 'page.version.created';
    }

    /**
     * @return array{page_uid: string, version_uid: string, version_number: int}
     */
    public function broadcastWith(): array
    {
        return [
            'page_uid' => $this->pageUid,
            'version_uid' => $this->versionUid,
            'version_number' => $this->versionNumber,
        ];
    }
}
