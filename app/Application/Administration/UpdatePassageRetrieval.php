<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\PassageRetrieval\PassageExtractor;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Jobs\BackfillPassageIndex;
use App\Jobs\PurgePassageGeneration;
use App\Models\InstallationSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class UpdatePassageRetrieval
{
    public function __construct(
        private InstallationSettingsWriter $writer,
        private InstallationLimitSettings $limits,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $actor, bool $enabled, int $observedGeneration): void
    {
        Gate::forUser($actor)->authorize('administer-system');
        DB::transaction(function () use ($actor, $enabled, $observedGeneration): void {
            $settings = $this->writer->locked();
            $previousEnabled = $settings->passage_retrieval_enabled ?? false;
            $previousGeneration = $settings->passage_retrieval_generation ?? 0;
            if ($enabled === $previousEnabled) {
                return;
            }
            if ($observedGeneration !== $previousGeneration) {
                throw new DomainRuleViolation('Passage retrieval changed since you opened this form. Review its current state and try again.');
            }
            if ($settings === null) {
                $settings = new InstallationSettings();
                $settings->forceFill($this->limits->current()->toPersistenceArray() + ['scope' => InstallationSettings::SCOPE_INSTALLATION]);
            }

            $generation = $previousGeneration + 1;
            $settings->forceFill([
                'passage_retrieval_enabled' => $enabled,
                'passage_retrieval_generation' => $generation,
                'passage_retrieval_extractor_revision' => $enabled ? PassageExtractor::REVISION : null,
                'updated_by_user_uid' => $actor->uid,
            ])->save();

            $type = DomainEventType::InstallationPassageRetrievalUpdated;
            $payload = ['previous_enabled' => $previousEnabled, 'enabled' => $enabled, 'generation' => $generation, 'updated_by_user_uid' => $actor->uid];
            $event = $this->events->record($type, 'installation_settings', $settings->uid, $payload);
            $this->audit->record($event, $actor->uid, 'installation_settings', $settings->uid, $type, 'Passage retrieval setting updated.', $payload);

            DB::afterCommit(static function () use ($enabled, $generation, $previousGeneration): void {
                try {
                    if ($enabled) {
                        BackfillPassageIndex::dispatch($generation, PassageExtractor::REVISION);
                    } else {
                        PurgePassageGeneration::dispatch($previousGeneration);
                    }
                } catch (Throwable) {
                    // The scheduler rediscovers any missed work from committed policy.
                    Log::warning('passage_retrieval.dispatch_failed', ['generation' => $generation]);
                }
            });
        });
    }
}
