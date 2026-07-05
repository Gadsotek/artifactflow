<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\MarkdownText;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class WorkspaceInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $invitedEmail,
        public readonly string $workspaceName,
        public readonly string $roleLabel,
        public readonly string $inviterName,
        public readonly string $acceptUrl,
        public readonly DateTimeInterface $expiresAt,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf("You've been invited to the %s workspace", $this->workspaceName),
        );
    }

    public function content(): Content
    {
        // The body is rendered as Markdown, so user-controlled names must be
        // Markdown-escaped or they could inject a disguised link. These keys are
        // distinct from the public properties (which the mailable would otherwise
        // let shadow same-named view data) and are used in place of them there.
        return new Content(
            markdown: 'mail.identity.workspace-invitation',
            with: [
                'inviterNameMarkdown' => MarkdownText::escapeInline($this->inviterName),
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
