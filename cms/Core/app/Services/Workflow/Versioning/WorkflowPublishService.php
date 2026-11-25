<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Services\Security\StepUpConfirmation;
use Cms\Core\Services\Workflow\Exceptions\WorkflowChangeRefused;
use Cms\Core\Services\Workflow\Exceptions\WorkflowIntegrityException;
use Cms\Core\Services\Workflow\Exceptions\WorkflowProjectNotPublished;
use Cms\Core\Services\Workflow\Exceptions\WorkflowPublishRefused;
use Cms\Core\Services\Workflow\Exceptions\WorkflowStepUpRequired;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Publishes workflow drafts as immutable, sealed versions (B1/B2) after the server-side policy (B4), with change
 * control on sensitive behaviour changes (B3).
 *
 * publish(): under a row lock of the project, the draft (its `workflow_steps` / `workflow_connections` rows and run
 * settings) is frozen with WorkflowVersionSnapshot. A draft equal to the published version adds nothing (path
 * `unchanged`). Otherwise it is checked with WorkflowPublishPolicy (WorkflowPublishRefused with the problems), numbered
 * (max + 1), hashed, fingerprinted (WorkflowSensitiveFingerprint) and sealed. gate() then decides:
 * - nothing sensitive changed against the published version → it goes live now (path `published`);
 * - something did (a first publish counts every sensitive element) → a step-up grant `workflow_publish` is required
 *   (WorkflowStepUpRequired otherwise), then `pending_review` when another publisher exists (they approve or reject;
 *   the author never approves), else `cooling_off` until `effective_at` (promoteDue(), the minute scheduler tick).
 *   The authors of a version are who pressed Publish and every draft editor since the draft last matched the live
 *   version (WorkflowAuthors, frozen into `editor_ids`): none of them approves it, and none counts as "another
 *   publisher" (review fix 14). Sensitive changes are compared with the live version's graph, recomputed from its
 *   verified snapshot, never its stored summary (review fix 15). Going live seals the project's live pointer.
 * A waiting version carries a pending-domain seal, never a run seal; it is re-sealed for runs only when it goes live.
 * Every new publish cancels the project's older waiting versions. When a version goes live the previous published one
 * becomes `superseded` and the project's published_version_id points at it. Runs already started keep their version.
 *
 * rollback() never re-points to an old row: it publishes a NEW version whose graph equals the target's (target live
 * and verified, policy re-checked, change control applied like any publish).
 *
 * Instances are configured per call: withStepUpGrant() (the `X-Step-Up-Grant` header of the request),
 * trustingStepUp() (the caller already confirmed who it is) and asOperator() (a server operator's console command:
 * no change-control layer). Each returns a copy.
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
class WorkflowPublishService
{
    /** Policy ability that allows publish, rollback and the change-control decisions (WorkflowProjectPolicy::publish()). */
    public const PUBLISH_ABILITY = 'publish';

    /** Policy ability that allows reading versions. */
    public const VIEW_ABILITY = 'view';

    /** Error code of a refused activation (no published version). */
    public const NOT_PUBLISHED_CODE = 'not_published';

    public const PATH_PUBLISHED = 'published';
    public const PATH_UNCHANGED = 'unchanged';

    public const WAITING_STATUSES = [WorkflowVersion::STATUS_PENDING_REVIEW, WorkflowVersion::STATUS_COOLING_OFF];

    protected ?string $stepUpGrant = null;

    protected bool $stepUpTrusted = false;

    protected bool $operator = false;

    public function __construct(protected WorkflowPublishPolicy $policy)
    {
    }

    /** A copy that checks $grant (sent in the `X-Step-Up-Grant` header) when a change needs step-up. */
    public function withStepUpGrant(?string $grant): static
    {
        $copy = clone $this;
        $copy->stepUpGrant = $grant;

        return $copy;
    }

    /** A copy for a caller that already confirmed who the user is (change control still applies). */
    public function trustingStepUp(): static
    {
        $copy = clone $this;
        $copy->stepUpTrusted = true;

        return $copy;
    }

    /**
     * A copy for a server operator's console command (shell access already outranks step-up and a second person):
     * sensitive changes go live at once. Never use it for a request.
     */
    public function asOperator(): static
    {
        $copy = clone $this;
        $copy->operator = true;

        return $copy;
    }

    /**
     * Publish the project's current draft.
     *
     * @throws WorkflowPublishRefused When the policy finds problems.
     * @throws WorkflowStepUpRequired When the change is sensitive and no valid step-up grant came with it.
     */
    public function publish(WorkflowProject $project, User $user, ?string $note = null): WorkflowVersion
    {
        return $this->store($project, $user, $note, static fn (WorkflowProject $locked): array => WorkflowVersionSnapshot::fromDraft($locked));
    }

    /**
     * Publish a new version whose graph equals $to.
     *
     * @throws InvalidArgumentException   When $to belongs to another project.
     * @throws WorkflowIntegrityException When $to fails its integrity check (never roll back to a tampered version).
     * @throws WorkflowPublishRefused     When $to never went live, or the policy finds problems in it today.
     * @throws WorkflowStepUpRequired     As publish().
     */
    public function rollback(WorkflowProject $project, WorkflowVersion $to, User $user): WorkflowVersion
    {
        if ((int) $to->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException('The version belongs to another workflow.');
        }
        if (!in_array((string) $to->status, WorkflowVersionSeal::LIVE_STATUSES, true)) {
            throw new WorkflowPublishRefused([['code' => 'version_not_live', 'message' => 'Only a version that was live can be restored.']]);
        }

        RunGraph::verify($to);
        $graph = $to->graphData();

        return $this->store($project, $user, 'Rollback to version ' . $to->number, static fn (): array => $graph, $to);
    }

    /**
     * Publish the draft of a system-managed project (agent schedule wrappers) when it differs from the published
     * version. Its graph is generated by code, not drawn in the designer, so the designer policy and change control do
     * not apply. Returns the published version (new or unchanged).
     */
    public function publishSystem(WorkflowProject $project, ?string $note = null): WorkflowVersion
    {
        return DB::transaction(function () use ($project, $note): WorkflowVersion {
            $locked = WorkflowProject::query()->lockForUpdate()->findOrFail($project->getKey());
            $graph = WorkflowVersionSnapshot::fromDraft($locked);
            $current = $this->publishedOf($locked);
            if ($current && hash_equals((string) $current->content_hash, WorkflowVersionSnapshot::hash($graph))) {
                return $current;
            }

            $version = $this->candidate($locked, $graph, null, $note ?? 'System-managed workflow updated.');
            $this->goLive($locked, $version);
            $project->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });
    }

    /**
     * What publishing the draft now would do (no side effects): the contract of `GET versions/preview-publish`.
     *
     * @return array{unchanged: bool, sensitive: bool, changes: list<array{kind: string, step_code?: string, detail: string}>, path: string, cooling_off_minutes: int, step_up_required: bool}
     */
    public function preview(WorkflowProject $project, User $user): array
    {
        $graph = WorkflowVersionSnapshot::fromDraft($project);
        $current = $this->publishedOf($project);
        $unchanged = $current !== null && hash_equals((string) $current->content_hash, WorkflowVersionSnapshot::hash($graph));
        $changes = $unchanged ? [] : $this->sensitiveChanges($current, WorkflowSensitiveFingerprint::of($graph)['summary']);
        $sensitive = $changes !== [];

        $path = self::PATH_PUBLISHED;
        if ($sensitive) {
            $authors = [...WorkflowAuthors::draftEditors($project), (int) $user->getKey()];
            $path = $this->otherPublisherExists($project, $authors) ? WorkflowVersion::STATUS_PENDING_REVIEW : WorkflowVersion::STATUS_COOLING_OFF;
        }

        return [
            'unchanged' => $unchanged,
            'sensitive' => $sensitive,
            'changes' => $changes,
            'path' => $path,
            'cooling_off_minutes' => $this->coolingOffMinutes(),
            'step_up_required' => $sensitive && !$this->hasStepUp($user, false),
        ];
    }

    /**
     * Sensitive changes of $version against the version published now (for the pending list).
     *
     * @return list<array{kind: string, step_code?: string, detail: string}>
     */
    public function changesOf(WorkflowVersion $version): array
    {
        $project = WorkflowProject::query()->find($version->project_id);

        // Recomputed from the version's own graph, never the stored (unsealed) summary (review fix 15).
        return $this->sensitiveChanges($project ? $this->publishedOf($project) : null, WorkflowSensitiveFingerprint::of($version->graphData())['summary']);
    }

    /**
     * Another publisher approves a waiting version (`pending_review`): policy re-checked, sealed for runs, live.
     *
     * @throws WorkflowChangeRefused      author_cannot_approve, not_allowed, not_pending.
     * @throws WorkflowStepUpRequired     Without a valid step-up grant.
     * @throws WorkflowPublishRefused     When the policy finds problems in it today.
     * @throws WorkflowIntegrityException When its content or pending seal was changed while it waited.
     */
    public function approve(WorkflowVersion $version, User $approver): WorkflowVersion
    {
        // The publisher and everyone whose draft edits went into the version are its authors (review fix 14).
        if (in_array((int) $approver->getKey(), WorkflowAuthors::ofVersion($version), true)) {
            throw WorkflowChangeRefused::authorCannotApprove();
        }
        $project = WorkflowProject::query()->findOrFail($version->project_id);
        if (!$approver->can(self::PUBLISH_ABILITY, $project)) {
            throw WorkflowChangeRefused::notAllowed();
        }
        if ($version->status !== WorkflowVersion::STATUS_PENDING_REVIEW) {
            throw WorkflowChangeRefused::notPending();
        }
        $this->assertStepUp($approver);

        try {
            $live = $this->promote($version, [WorkflowVersion::STATUS_PENDING_REVIEW], $approver);
        } catch (WorkflowIntegrityException $e) {
            WorkflowIntegrity::violated($e, null, 'approve');
            throw $e;
        }
        WorkflowAudit::project(WorkflowAudit::VERSION_APPROVED, $project, ['version' => $live->number], 'warning', (int) $approver->getKey());
        $this->alert('version_published', $project, ['version' => $live->number], $approver);

        return $live;
    }

    /**
     * Another publisher refuses a waiting version.
     *
     * @throws WorkflowChangeRefused
     */
    public function reject(WorkflowVersion $version, User $user, ?string $reason = null): WorkflowVersion
    {
        $project = WorkflowProject::query()->findOrFail($version->project_id);
        if ((int) $version->created_by === (int) $user->getKey() || !$user->can(self::PUBLISH_ABILITY, $project)) {
            throw WorkflowChangeRefused::notAllowed();
        }

        $version = $this->close($version, [WorkflowVersion::STATUS_PENDING_REVIEW], WorkflowVersion::STATUS_REJECTED);
        WorkflowAudit::project(WorkflowAudit::VERSION_REJECTED, $project, [
            'version' => $version->number,
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
        ], 'warning', (int) $user->getKey());

        return $version;
    }

    /**
     * The author (or a Webmaster) withdraws a waiting version.
     *
     * @throws WorkflowChangeRefused
     */
    public function cancel(WorkflowVersion $version, User $user): WorkflowVersion
    {
        $project = WorkflowProject::query()->findOrFail($version->project_id);
        if (!$this->canCancel($version, $user)) {
            throw WorkflowChangeRefused::notAllowed();
        }

        $version = $this->close($version, self::WAITING_STATUSES, WorkflowVersion::STATUS_CANCELLED);
        WorkflowAudit::project(WorkflowAudit::VERSION_CANCELLED, $project, ['version' => $version->number], 'warning', (int) $user->getKey());

        return $version;
    }

    public function canCancel(WorkflowVersion $version, User $user): bool
    {
        return (int) $version->created_by === (int) $user->getKey() || $user->isWebmaster();
    }

    public function canApprove(WorkflowVersion $version, User $user, WorkflowProject $project): bool
    {
        return $version->status === WorkflowVersion::STATUS_PENDING_REVIEW
            && !in_array((int) $user->getKey(), WorkflowAuthors::ofVersion($version), true)
            && $user->can(self::PUBLISH_ABILITY, $project);
    }

    /**
     * Minute tick: put live every `cooling_off` version whose time has come. The policy is checked again, the project
     * must still exist and its author must still hold `publish`; a version that fails is cancelled (and audited).
     *
     * @return array{promoted: list<int>, cancelled: list<int>}
     */
    public function promoteDue(): array
    {
        $result = ['promoted' => [], 'cancelled' => []];
        $due = WorkflowVersion::query()
            ->where('status', WorkflowVersion::STATUS_COOLING_OFF)
            ->where('effective_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($due as $version) {
            $project = WorkflowProject::query()->find($version->project_id);
            $author = $version->created_by ? User::query()->find($version->created_by) : null;
            $reason = null;
            if ($project === null) {
                $reason = 'project_missing';
            } elseif ($author === null || !$author->can(self::PUBLISH_ABILITY, $project)) {
                $reason = 'author_cannot_publish';
            }

            try {
                if ($reason === null) {
                    $live = $this->promote($version, [WorkflowVersion::STATUS_COOLING_OFF], null);
                    WorkflowAudit::project(WorkflowAudit::VERSION_PROMOTED, $project, ['version' => $live->number], 'warning');
                    $this->alert('version_published', $project, ['version' => $live->number, 'reason' => 'cooling_off_ended'], $author);
                    $result['promoted'][] = (int) $version->id;
                    continue;
                }
            } catch (WorkflowChangeRefused) {
                continue; // decided meanwhile (cancelled, or a newer publish)
            } catch (WorkflowPublishRefused) {
                $reason = 'policy';
            } catch (WorkflowIntegrityException $e) {
                $reason = 'integrity';
                WorkflowIntegrity::violated($e, null, 'promote');
            } catch (Throwable $e) {
                report($e);
                continue;
            }

            WorkflowVersion::query()->whereKey($version->id)->where('status', WorkflowVersion::STATUS_COOLING_OFF)
                ->update(['status' => WorkflowVersion::STATUS_CANCELLED, 'updated_at' => now()]);
            if ($project !== null) {
                WorkflowAudit::project(WorkflowAudit::VERSION_CANCELLED, $project, ['version' => $version->number, 'reason' => $reason], 'warning');
            }
            $result['cancelled'][] = (int) $version->id;
        }

        return $result;
    }

    /**
     * Whether the draft differs from the published version (hash compare).
     *
     * @return array{published_version: ?string, published_number: ?int, draft_hash: string, published_hash: ?string, differs: bool}
     */
    public function draftStatus(WorkflowProject $project): array
    {
        $draftHash = WorkflowVersionSnapshot::hash(WorkflowVersionSnapshot::fromDraft($project));
        $published = $this->publishedOf($project);

        return [
            'published_version' => $published?->uuid,
            'published_number' => $published?->number,
            'draft_hash' => $draftHash,
            'published_hash' => $published?->content_hash,
            'differs' => $published === null || !hash_equals((string) $published->content_hash, $draftHash),
        ];
    }

    /**
     * Refuse turning a project on while it has no published version.
     *
     * @throws WorkflowProjectNotPublished
     */
    public function assertCanActivate(WorkflowProject $project): void
    {
        if ((int) ($project->published_version_id ?? 0) <= 0) {
            throw new WorkflowProjectNotPublished('The workflow has no published version, so it can not be turned on. Publish it first.');
        }
    }

    /**
     * Change control (B3): status the candidate takes instead of going live (`pending_review`, `cooling_off`), or null
     * to publish now. The candidate (its sensitive changes in publishChanges) is sealed but not saved yet.
     *
     * @throws WorkflowStepUpRequired
     */
    protected function gate(WorkflowProject $project, WorkflowVersion $candidate, User $user): ?string
    {
        if ($candidate->publishChanges === [] || $this->operator) {
            return null;
        }

        $this->assertStepUp($user);

        if ($this->otherPublisherExists($project, [...WorkflowAuthors::ofVersion($candidate), (int) $user->getKey()])) {
            return WorkflowVersion::STATUS_PENDING_REVIEW;
        }

        $candidate->effective_at = now()->addMinutes($this->coolingOffMinutes());

        return WorkflowVersion::STATUS_COOLING_OFF;
    }

    /**
     * @param callable(WorkflowProject): array<string, mixed> $graphOf Graph to freeze, read under the project lock.
     */
    protected function store(WorkflowProject $project, User $user, ?string $note, callable $graphOf, ?WorkflowVersion $rollbackOf = null): WorkflowVersion
    {
        $previousNumber = null;
        $version = DB::transaction(function () use ($project, $user, $note, $graphOf, $rollbackOf, &$previousNumber): WorkflowVersion {
            $locked = WorkflowProject::query()->lockForUpdate()->findOrFail($project->getKey());
            $graph = $graphOf($locked);
            $current = $this->publishedOf($locked);
            $previousNumber = $current?->number;

            if ($current && hash_equals((string) $current->content_hash, WorkflowVersionSnapshot::hash($graph))) {
                $current->publishPath = self::PATH_UNCHANGED;

                return $current;
            }

            $problems = $this->policy->problems($locked, $graph, $user);
            if ($problems !== []) {
                throw new WorkflowPublishRefused($problems);
            }

            // A rollback carries the authors of the version it restores; a publish freezes the draft editors.
            $editors = $rollbackOf !== null ? WorkflowAuthors::ofVersion($rollbackOf) : WorkflowAuthors::draftEditors($locked);
            $version = $this->candidate($locked, $graph, (int) $user->getKey(), $note, $editors);
            $version->publishChanges = $this->sensitiveChanges($current, (array) $version->sensitive_summary);
            $status = $this->gate($locked, $version, $user);

            // A newer publish of the project replaces whatever still waits.
            $this->cancelWaiting($locked, $user);

            if ($status === null) {
                $this->goLive($locked, $version);
                $this->releaseScheduleOf($locked, (int) $user->getKey());
                $version->publishPath = self::PATH_PUBLISHED;
            } else {
                $version->status = $status;
                // Never a run seal while it waits.
                $version->seal = WorkflowVersionSeal::sign((int) $locked->getKey(), (int) $version->number, (string) $version->content_hash, null, WorkflowVersionSeal::PENDING_HKDF_INFO);
                $version->save();
                $version->publishPath = $status;
            }

            $project->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });

        // Alerts and audit after the commit (Telegram must never run under the project lock).
        if ($version->publishPath === self::PATH_PUBLISHED) {
            $rollbackOf
                ? $this->alert('version_rolled_back', $project, ['version' => $version->number, 'from_version' => $previousNumber, 'to_version' => $rollbackOf->number], $user)
                : $this->alert('version_published', $project, ['version' => $version->number], $user);
        } elseif ($version->publishPath !== self::PATH_UNCHANGED) {
            WorkflowAudit::project(WorkflowAudit::VERSION_PENDING, $project, [
                'version' => $version->number,
                'status' => $version->status,
                'changes' => array_values(array_unique(array_column($version->publishChanges, 'kind'))),
            ], 'warning', (int) $user->getKey());
            $this->alert('version_pending', $project, [
                'version' => $version->number,
                'reason' => $version->status,
                'count' => count($version->publishChanges),
                'effective_at' => $version->effective_at?->toISOString(),
            ], $user);
        }

        return $version;
    }

    /**
     * Put a waiting version live under the project lock: content and pending seal verified, policy re-checked, sealed
     * for runs, previous version superseded.
     *
     * @param list<string> $expected Statuses it must still have.
     */
    protected function promote(WorkflowVersion $version, array $expected, ?User $approver): WorkflowVersion
    {
        return DB::transaction(function () use ($version, $expected, $approver): WorkflowVersion {
            $locked = WorkflowProject::query()->lockForUpdate()->findOrFail($version->project_id);
            $fresh = WorkflowVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if (!in_array($fresh->status, $expected, true)) {
                throw WorkflowChangeRefused::notPending();
            }
            if (!WorkflowVersionSeal::verifyPending($fresh)) {
                throw new WorkflowIntegrityException((int) $locked->getKey(), (int) $fresh->id, 'seal_mismatch');
            }

            $author = $fresh->created_by ? User::query()->find($fresh->created_by) : null;
            $problems = $this->policy->problems($locked, $fresh->graphData(), $approver ?? $author);
            if ($problems !== []) {
                throw new WorkflowPublishRefused($problems);
            }

            $runSeal = WorkflowVersionSeal::sign((int) $fresh->project_id, (int) $fresh->number, (string) $fresh->content_hash);
            // A query update: the model refuses to change `seal`.
            WorkflowVersion::query()->whereKey($fresh->id)->update(['seal' => $runSeal]);
            $fresh->setRawAttributes(array_merge($fresh->getAttributes(), ['seal' => $runSeal]), true);

            $fresh->forceFill(['approved_by' => $approver?->getKey(), 'approved_at' => now()]);
            $this->goLive($locked, $fresh);
            if ($fresh->created_by) {
                $this->releaseScheduleOf($locked, (int) $fresh->created_by);
            }
            $fresh->publishPath = self::PATH_PUBLISHED;

            return $fresh;
        });
    }

    /**
     * @param list<string> $from
     */
    protected function close(WorkflowVersion $version, array $from, string $to): WorkflowVersion
    {
        return DB::transaction(function () use ($version, $from, $to): WorkflowVersion {
            WorkflowProject::query()->lockForUpdate()->findOrFail($version->project_id);
            $fresh = WorkflowVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if (!in_array($fresh->status, $from, true)) {
                throw WorkflowChangeRefused::notPending();
            }
            $fresh->status = $to;
            $fresh->save();

            return $fresh;
        });
    }

    protected function cancelWaiting(WorkflowProject $locked, User $user): void
    {
        $waiting = WorkflowVersion::query()->where('project_id', $locked->getKey())->whereIn('status', self::WAITING_STATUSES)->get();
        foreach ($waiting as $old) {
            $old->status = WorkflowVersion::STATUS_CANCELLED;
            $old->save();
            WorkflowAudit::project(WorkflowAudit::VERSION_CANCELLED, $locked, ['version' => $old->number, 'reason' => 'newer_publish'], 'warning', (int) $user->getKey());
        }
    }

    /**
     * Sensitive changes of a summary against the published version ([] = not sensitive).
     *
     * @param array<string, mixed> $summary
     * @return list<array{kind: string, step_code?: string, detail: string}>
     */
    protected function sensitiveChanges(?WorkflowVersion $current, array $summary): array
    {
        return WorkflowSensitiveFingerprint::changes($this->liveSummary($current), $summary);
    }

    /**
     * Sensitive summary of the live version, recomputed from its verified graph (review fix 15): the stored
     * `sensitive_summary` is not sealed, so an inflated copy must not make the next change look harmless. A live version
     * that fails its check counts as empty, so every sensitive element of the new version is a change.
     *
     * @return array<string, mixed>
     */
    protected function liveSummary(?WorkflowVersion $current): array
    {
        if ($current === null) {
            return WorkflowSensitiveFingerprint::emptySummary();
        }

        try {
            RunGraph::verify($current);
        } catch (WorkflowIntegrityException) {
            return WorkflowSensitiveFingerprint::emptySummary();
        }

        return WorkflowSensitiveFingerprint::of($current->graphData())['summary'];
    }

    /**
     * @throws WorkflowStepUpRequired
     */
    protected function assertStepUp(User $user): void
    {
        if (!$this->hasStepUp($user, true)) {
            throw new WorkflowStepUpRequired(StepUpConfirmation::PURPOSE_WORKFLOW_PUBLISH);
        }
    }

    protected function hasStepUp(User $user, bool $consume): bool
    {
        if ($this->stepUpTrusted || $this->operator) {
            return true;
        }

        return app(StepUpConfirmation::class)->checkGrant(
            $user,
            $user->currentAccessToken(),
            $this->stepUpGrant,
            StepUpConfirmation::PURPOSE_WORKFLOW_PUBLISH,
            $consume,
        );
    }

    /**
     * Whether someone who is not one of $authors (user ids) can publish this project (and so review the change).
     *
     * @param list<int> $authors
     */
    protected function otherPublisherExists(WorkflowProject $project, array $authors): bool
    {
        foreach (app(WorkflowChangeAlerter::class)->recipients() as $candidate) {
            if (!in_array((int) $candidate->getKey(), $authors, true) && $candidate->can(self::PUBLISH_ABILITY, $project)) {
                return true;
            }
        }

        return false;
    }

    protected function coolingOffMinutes(): int
    {
        return max(1, (int) config('core.workflow.cooling_off_minutes', 60));
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function alert(string $event, WorkflowProject $project, array $details, ?User $actor): void
    {
        try {
            app(WorkflowChangeAlerter::class)->notify($event, $project, $details, $actor);
        } catch (Throwable $e) {
            // An alert failure never undoes a publish; it is reported.
            report($e);
        }
    }

    protected function publishedOf(WorkflowProject $project): ?WorkflowVersion
    {
        return $project->published_version_id ? WorkflowVersion::query()->find($project->published_version_id) : null;
    }

    /**
     * Scheduled runs act for `scheduled_by_user_id`; when a version made by someone else goes live (a publish, a
     * rollback, an approved or promoted change), the schedule is cleared like any graph change by another user
     * (WorkflowScheduleOwnershipObserver), so it runs again only after its new owner sets it.
     */
    protected function releaseScheduleOf(WorkflowProject $locked, int $userId): void
    {
        $scheduler = $locked->scheduled_by_user_id;
        if ($scheduler === null || (int) $scheduler === $userId) {
            return;
        }

        WorkflowProject::query()->whereKey($locked->getKey())
            ->update(['schedule_cron' => null, 'schedule_timezone' => null, 'scheduled_by_user_id' => null]);
        $locked->forceFill(['schedule_cron' => null, 'schedule_timezone' => null, 'scheduled_by_user_id' => null])->syncOriginal();
    }

    /**
     * A sealed (run seal), fingerprinted, unsaved version of $graph with the next number.
     *
     * @param array<string, mixed> $graph
     */
    protected function candidate(WorkflowProject $locked, array $graph, ?int $userId, ?string $note, array $editors = []): WorkflowVersion
    {
        $json = WorkflowVersionSnapshot::encode($graph);
        $hash = WorkflowVersionSnapshot::hashText($json);
        $number = (int) WorkflowVersion::query()->where('project_id', $locked->getKey())->max('number') + 1;
        $fingerprint = WorkflowSensitiveFingerprint::of($graph);

        $version = new WorkflowVersion();
        $version->forceFill([
            'project_id' => (int) $locked->getKey(),
            'number' => $number,
            'graph' => $json,
            'content_hash' => $hash,
            'sensitive_fingerprint' => $fingerprint['hash'],
            'sensitive_summary' => $fingerprint['summary'],
            'seal' => WorkflowVersionSeal::sign((int) $locked->getKey(), $number, $hash),
            'created_by' => $userId,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
        ]);
        if (WorkflowAuthors::tracked()) {
            $version->forceFill(['editor_ids' => array_values(array_unique(array_map('intval', $editors)))]);
        }

        return $version;
    }

    /**
     * Save $version as the published one: the previous published version is superseded, the project points at it.
     */
    protected function goLive(WorkflowProject $locked, WorkflowVersion $version): void
    {
        $version->status = WorkflowVersion::STATUS_PUBLISHED;
        $version->save();

        WorkflowVersion::query()
            ->where('project_id', $locked->getKey())
            ->where('status', WorkflowVersion::STATUS_PUBLISHED)
            ->whereKeyNot($version->getKey())
            ->update(['status' => WorkflowVersion::STATUS_SUPERSEDED, 'updated_at' => now()]);

        // A model save: the trigger index is cleared and the project observer audits the change. The pointer is sealed
        // with it (review fix 15): new runs refuse a pointer a database write moved.
        $pointer = ['published_version_id' => $version->getKey()];
        if (WorkflowVersionSeal::pointerSealed()) {
            $pointer['published_version_seal'] = WorkflowVersionSeal::signPointer((int) $locked->getKey(), (int) $version->getKey());
        }
        $locked->forceFill($pointer)->save();
        WorkflowAuthors::settleDraft($locked, $version);
    }
}
