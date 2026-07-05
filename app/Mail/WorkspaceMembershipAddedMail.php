<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\MarkdownText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Informational notice — not an invitation. Sent when an existing collaborator
 * is added straight into a shared workspace; there is nothing to accept, so the
 * message only tells the recipient they now have access and where to find it.
 */
final class WorkspaceMembershipAddedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $workspaceName,
        public readonly string $roleLabel,
        public readonly string $addedByName,
        public readonly string $workspaceUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('You now have access to the %s workspace', $this->workspaceName),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.workspace-membership-added',
            with: [
                'addedByNameMarkdown' => MarkdownText::escapeInline($this->addedByName),
                'workspaceNameMarkdown' => MarkdownText::escapeInline($this->workspaceName),
            ],
        );
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
