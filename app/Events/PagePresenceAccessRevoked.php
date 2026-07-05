<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final readonly class PagePresenceAccessRevoked implements ShouldBroadcast
{
    public function __construct(
        private string $pageUid,
        private string $userUid,
    ) {
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('page.' . $this->pageUid);
    }

    public function broadcastAs(): string
    {
        return 'page.access.revoked';
    }

    /**
     * @return array{page_uid: string, uid: string}
     */
    public function broadcastWith(): array
    {
        return [
            'page_uid' => $this->pageUid,
            'uid' => $this->userUid,
        ];
    }
}
