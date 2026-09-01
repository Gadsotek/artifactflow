<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Mcp\McpEffectiveAuthority;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\DocxProcessorConfiguration;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Application\PageCatalog\XlsxProcessorConfiguration;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\ExternalShare;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class CreateExternalShare
{
    public function __construct(
        private PageAccess $access,
        private ExternalSharingPolicySettings $policySettings,
        private ExternalShareLimits $limits,
        private ActiveExternalShares $activeShares,
        private ExternalShareSecret $secrets,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpEffectiveAuthority $mcpAuthority,
        private McpRequestContext $mcpContext,
        private PdfProcessorConfiguration $pdfConfiguration,
        private XlsxProcessorConfiguration $xlsxConfiguration,
        private DocxProcessorConfiguration $docxConfiguration,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, CreateExternalShareCommand $command): IssuedExternalShare
    {
        return $this->create($actor, $command, false);
    }

    /**
     * MCP external sharing is deliberately narrower than browser access
     * management: an in-scope principal may share only a page it owns and can
     * still edit. The dedicated mcp:share scope is enforced by the MCP adapter.
     *
     * @throws AuthorizationException
     */
    public function handleForMcp(User $actor, CreateExternalShareCommand $command): IssuedExternalShare
    {
        if (!$this->mcpAuthority->isActive()) {
            throw new AuthorizationException('MCP external sharing requires an active MCP request.');
        }

        return $this->create($actor, $command, true);
    }

    /**
     * @throws AuthorizationException
     */
    private function create(
        User $actor,
        CreateExternalShareCommand $command,
        bool $forMcp,
    ): IssuedExternalShare {
        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actorUid, $command, $forMcp): IssuedExternalShare {
            $page = $this->access->lockAndReauthorize(
                $command->pageUid,
                function (Page $lockedPage) use ($actorUid, $forMcp): void {
                    $freshActor = User::query()->find($actorUid);

                    if (!$freshActor instanceof User) {
                        throw new AuthorizationException('The external share creator no longer exists.');
                    }

                    $this->access->lockWorkspaceSharingPolicy($lockedPage->workspace_uid);

                    if ($forMcp) {
                        if (!$this->access->canShareOwnedPageViaMcp($freshActor, $lockedPage)) {
                            throw new AuthorizationException(
                                'MCP may share only an owned editable page when its workspace permits editor sharing.',
                            );
                        }

                        return;
                    }

                    if ($freshActor->is_service_account) {
                        throw new AuthorizationException('Only human access managers can create external shares.');
                    }

                    $this->access->ensureCanManageAccess($freshActor, $lockedPage);
                },
            );

            if ($page->status === PageStatus::Archived) {
                throw new DomainRuleViolation('Archived pages cannot be shared externally.');
            }

            if ($page->type === PageType::Pdf && !$this->pdfConfiguration->enabled()) {
                throw new DomainRuleViolation(
                    'External sharing is not available while PDF artifacts are disabled.',
                );
            }

            if ($page->type === PageType::Xlsx && !$this->xlsxConfiguration->enabled()) {
                throw new DomainRuleViolation(
                    'External sharing is not available while Excel workbook artifacts are disabled.',
                );
            }

            if ($page->type === PageType::Docx
                && (!$this->docxConfiguration->enabled() || !$this->pdfConfiguration->enabled())) {
                throw new DomainRuleViolation(
                    'External sharing is not available while Word document artifacts are disabled.',
                );
            }

            // The installation settings row serializes the installation-wide
            // count, but only after this page has been authorized and locked.
            // A transaction waiting on one page therefore cannot stall share
            // creation for every unrelated page.
            $policy = $this->policySettings->current(lockForUpdate: true);

            if (!$policy->enabled) {
                throw new DomainRuleViolation('External sharing is disabled for this installation.');
            }

            $now = CarbonImmutable::now();
            $expiresAt = $this->validatedExpiry($command, $policy, $now);
            $this->ensureCapacity($page->uid, $now);
            $generated = $this->secrets->generate();

            $share = ExternalShare::query()->forceCreate([
                'page_uid' => $page->uid,
                'secret_hash' => $generated->hash,
                'mode' => $command->mode,
                'expires_at' => $expiresAt,
                'page_workspace_uid' => $page->workspace_uid,
                'page_access_revision' => $page->preview_access_revision,
                'created_by_user_uid' => $actorUid,
                'view_session_count' => 0,
            ]);

            $payload = [
                'external_share_uid' => $share->uid,
                'page_uid' => $page->uid,
                'mode' => $share->mode->value,
                'expires_at' => $share->expires_at?->toISOString(),
                'created_by_user_uid' => $actorUid,
                ...$this->mcpContext->auditMetadata(),
            ];
            $event = $this->events->record(
                eventType: DomainEventType::PageExternalShareCreated,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: $payload,
            );
            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'external_share',
                auditableUid: $share->uid,
                action: DomainEventType::PageExternalShareCreated,
                summary: 'External share created.',
                metadata: $payload,
            );

            return new IssuedExternalShare($share, $generated->reveal());
        });
    }

    private function validatedExpiry(
        CreateExternalShareCommand $command,
        ExternalSharingPolicy $policy,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        if ($command->mode === ExternalShareMode::OneTime) {
            if ($command->expiresAt instanceof CarbonImmutable) {
                throw new DomainRuleViolation('One-time external shares cannot have an expiry.');
            }

            return null;
        }

        if (!$command->expiresAt instanceof CarbonImmutable) {
            throw new DomainRuleViolation('Expiring external shares require an expiry.');
        }

        if ($command->expiresAt->lessThanOrEqualTo($now)) {
            throw new DomainRuleViolation('External share expiry must be in the future.');
        }

        if ($command->expiresAt->greaterThan($now->addHours($policy->maxExpiryHours))) {
            throw new DomainRuleViolation('External share expiry exceeds the installation maximum.');
        }

        return $command->expiresAt->utc();
    }

    private function ensureCapacity(string $pageUid, CarbonImmutable $now): void
    {
        if ($this->activeShares->countForPage($pageUid, $now) >= $this->limits->maxActivePerPage()) {
            throw new DomainRuleViolation('This page has reached its active external share limit.');
        }

        if ($this->activeShares->countForInstallation($now) >= $this->limits->maxActivePerInstallation()) {
            throw new DomainRuleViolation('This installation has reached its active external share limit.');
        }
    }
}
