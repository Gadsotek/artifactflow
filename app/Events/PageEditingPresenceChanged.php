<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class PageEditingPresenceChanged implements ShouldBroadcastNow
{
    public function __construct(
        private string $pageUid,
        private string $userUid,
        private string $userName,
        private bool $editing,
    ) {
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('page.' . $this->pageUid);
    }

    public function broadcastAs(): string
    {
        return 'page.editing';
    }

    /**
     * @return array{uid: string, name: string, editing: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'uid' => $this->userUid,
            'name' => $this->userName,
            'editing' => $this->editing,
        ];
    }
}
