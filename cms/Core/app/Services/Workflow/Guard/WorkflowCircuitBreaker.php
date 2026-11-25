<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Guard;

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Per-workflow circuit breakers (roadmap B7). Limits come from `core.workflow_guard.breaker` (0 = off):
 * runs per hour (60), publish/external_call steps per hour (30), cost per hour (5.00 USD), error rate (50 % of the
 * last 10 finished runs).
 *
 * {@see self::check()} runs before a run starts (lane 3 wires it into every run start): it answers the reason when a
 * rate limit is reached, and the first time it does it pauses the project. {@see self::afterRun()} runs when a run
 * reaches a terminal state and pauses on a high error rate. A pause sets `is_active = false`, `paused_reason =
 * breaker:<reason>` and `paused_at`, writes an audit entry and sends one `auto_paused` alert; the conditional update
 * (`where is_active = true`) makes a second trip of an already paused project silent. {@see self::resume()} is the
 * person's way back.
 *
 * @package Cms\Core\Services\Workflow\Guard
 * @since   2026-11-24
 */
class WorkflowCircuitBreaker
{
    public const REASON_PREFIX = 'breaker:';
    public const RUNS_PER_HOUR = 'runs_per_hour';
    public const ACTIONS_PER_HOUR = 'actions_per_hour';
    public const COST_PER_HOUR = 'cost_per_hour';
    public const ERROR_RATE = 'error_rate';

    /** Safety classes counted by the actions-per-hour limit. */
    public const COUNTED_SAFETY = ['publish', 'external_call'];

    private const FAILED_STATUSES = ['failed', 'apply_failed', 'budget_exceeded'];
    private const FINISHED_STATUSES = ['completed', 'success', 'applied', 'failed', 'apply_failed', 'budget_exceeded'];

    public function __construct(private readonly WorkflowChangeAlerter $alerter)
    {
    }

    /**
     * The breaker reason when a rate limit of the project is reached (and the project is then paused), else null.
     */
    public function check(WorkflowProject $project): ?string
    {
        $hit = $this->evaluate($project);
        if ($hit !== null) {
            $this->trip($project, $hit);
        }

        return $hit['reason'] ?? null;
    }

    /**
     * Which rate limit of the project is reached, without pausing it. The run service calls this while it holds the
     * project row lock, so concurrent starts cannot pass the hourly limits together (review finding 26), and pauses
     * with {@see self::trip()} after its transaction rolled back.
     *
     * @return array{reason: string, value: int|float, limit: int|float}|null
     */
    public function evaluate(WorkflowProject $project): ?array
    {
        $since = now()->subHour();
        $reason = null;
        $value = null;
        $limit = null;

        $runsLimit = (int) $this->limit(self::RUNS_PER_HOUR);
        if ($runsLimit > 0) {
            $runs = WorkflowInstance::query()->where('project_id', $project->getKey())->where('created_at', '>=', $since)->count();
            if ($runs >= $runsLimit) {
                [$reason, $value, $limit] = [self::RUNS_PER_HOUR, $runs, $runsLimit];
            }
        }

        $actionsLimit = (int) $this->limit(self::ACTIONS_PER_HOUR);
        if ($reason === null && $actionsLimit > 0) {
            $actions = $this->actionsSince($project, $since);
            if ($actions >= $actionsLimit) {
                [$reason, $value, $limit] = [self::ACTIONS_PER_HOUR, $actions, $actionsLimit];
            }
        }

        $costLimit = (float) $this->limit(self::COST_PER_HOUR);
        if ($reason === null && $costLimit > 0 && Schema::hasColumn((new WorkflowInstance())->getTable(), 'usage_cost_usd')) {
            $cost = (float) WorkflowInstance::query()->where('project_id', $project->getKey())
                ->where('created_at', '>=', $since)->sum('usage_cost_usd');
            if ($cost >= $costLimit) {
                [$reason, $value, $limit] = [self::COST_PER_HOUR, round($cost, 4), $costLimit];
            }
        }

        return $reason === null ? null : ['reason' => $reason, 'value' => $value, 'limit' => $limit];
    }

    /**
     * Pause the project for a limit {@see self::evaluate()} found.
     *
     * @param array{reason: string, value: int|float, limit: int|float} $hit
     */
    public function trip(WorkflowProject $project, array $hit): void
    {
        $this->pause($project, $hit['reason'], $hit['value'] ?? null, $hit['limit'] ?? null);
    }

    /**
     * Whether a step of this type and config publishes or calls out (counted by the actions limits).
     *
     * @param array<string, mixed> $config
     */
    public static function countsAsAction(string $stepType, array $config): bool
    {
        try {
            $safety = NodeRegistry::current()->get($stepType)?->safetyFor($config) ?? [];
        } catch (Throwable) {
            return false;
        }

        return array_intersect($safety, self::COUNTED_SAFETY) !== [];
    }

    /**
     * Evaluate the error rate once a run is finished; pauses the project when the last `error_window` finished runs
     * failed at or above `error_rate`. Returns the reason when it paused.
     */
    public function afterRun(WorkflowInstance $instance): ?string
    {
        $rate = (float) $this->limit(self::ERROR_RATE);
        $window = (int) config('core.workflow_guard.breaker.error_window', 10);
        if ($rate <= 0 || $window <= 0 || !in_array($instance->status, self::FINISHED_STATUSES, true)) {
            return null;
        }

        $statuses = WorkflowInstance::query()
            ->where('project_id', $instance->project_id)
            ->whereIn('status', self::FINISHED_STATUSES)
            ->orderByDesc('id')
            ->limit($window)
            ->pluck('status')
            ->all();
        if (count($statuses) < $window) {
            return null;
        }

        $failed = count(array_filter($statuses, static fn ($s): bool => in_array($s, self::FAILED_STATUSES, true)));
        if ($failed / $window < $rate) {
            return null;
        }

        $project = WorkflowProject::query()->find($instance->project_id);
        if ($project === null) {
            return null;
        }
        $this->pause($project, self::ERROR_RATE, $failed, $window);

        return self::ERROR_RATE;
    }

    /**
     * Switch a breaker-paused project back on (a person decided). Answers false when it was not paused by a breaker.
     */
    public function resume(WorkflowProject $project, ?User $actor): bool
    {
        $reason = (string) $project->getAttribute('paused_reason');
        if (!str_starts_with($reason, self::REASON_PREFIX)) {
            return false;
        }

        $project->forceFill(['is_active' => true, 'paused_reason' => null, 'paused_at' => null])->save();

        $userId = $actor?->getKey();
        WorkflowAudit::project(WorkflowAudit::BREAKER_RESUMED, $project, ['reason' => $reason], 'success', is_numeric($userId) ? (int) $userId : null);
        $this->alerter->notify('activation_changed', $project, ['active' => true, 'reason' => 'resumed after ' . $reason], $actor);

        return true;
    }

    /**
     * Pause once: only the update that switches an active project off audits and alerts.
     */
    protected function pause(WorkflowProject $project, string $reason, int|float|null $value, int|float|null $limit): void
    {
        $paused = WorkflowProject::query()
            ->whereKey($project->getKey())
            ->where('is_active', true)
            ->update(['is_active' => false, 'paused_reason' => self::REASON_PREFIX . $reason, 'paused_at' => now()]);
        if ($paused === 0) {
            return;
        }

        $project->refresh();
        WorkflowAudit::project(WorkflowAudit::BREAKER_PAUSED, $project, ['reason' => $reason, 'value' => $value, 'limit' => $limit], 'danger');
        $this->alerter->notify('auto_paused', $project, ['reason' => $reason, 'count' => $value, 'limit' => $limit]);
    }

    /**
     * Steps of the project's runs in the last hour whose node publishes or calls out.
     */
    protected function actionsSince(WorkflowProject $project, \DateTimeInterface $since): int
    {
        $counts = WorkflowInstanceStep::query()
            ->join('workflow_instances', 'workflow_instances.id', '=', 'workflow_instance_steps.instance_id')
            ->where('workflow_instances.project_id', $project->getKey())
            ->where('workflow_instance_steps.created_at', '>=', $since)
            ->whereNotIn('workflow_instance_steps.status', ['skipped', 'pending'])
            ->groupBy('workflow_instance_steps.step_id')
            ->selectRaw('workflow_instance_steps.step_id as step_id, count(*) as aggregate')
            ->pluck('aggregate', 'step_id')
            ->all();
        if ($counts === []) {
            return 0;
        }

        $total = 0;
        $steps = WorkflowStep::withTrashed()->whereIn('id', array_keys($counts))->get(['id', 'step_type', 'step_config']);
        foreach ($steps as $step) {
            if (self::countsAsAction((string) $step->step_type, (array) ($step->step_config ?? []))) {
                $total += (int) $counts[$step->id];
            }
        }

        return $total;
    }

    private function limit(string $key): int|float
    {
        return config('core.workflow_guard.breaker.' . $key, 0) ?? 0;
    }
}
