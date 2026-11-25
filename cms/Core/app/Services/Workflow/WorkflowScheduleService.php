<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cron\CronExpression;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;

/**
 * The schedule rule of a workflow project, shared by `PUT workflow/projects/{project}/schedule` and the setup screen.
 *
 * The caller must already hold the project `update` ability. A system-managed project is refused. A cron is a valid
 * expression (max 120 chars) with an optional valid timezone; scheduled runs act for the user who set it
 * (`scheduled_by_user_id`, the scheduler-ownership rule). An empty cron turns the schedule off. A change is audited.
 *
 * "Run only by hand" is an answer of its own on the setup screen: it clears the schedule and tags the project
 * MANUAL_TAG, so the setup question counts as answered without a cron.
 *
 * @package Cms\Core\Services\Workflow
 * @since   2026-11-24
 */
class WorkflowScheduleService
{
    public const MANUAL_TAG = 'setup:run-by-hand';

    public function __construct(protected WorkflowProjectRunService $runService)
    {
    }

    /**
     * Why the schedule cannot be set, or null.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function problem(WorkflowProject $project, mixed $cron, mixed $timezone): ?array
    {
        if ($this->runService->isSystemManaged($project)) {
            $e = new Exceptions\WorkflowProjectNotRunnable();

            return ['code' => $e::CODE, 'message' => $e->getMessage(), 'status' => 422];
        }

        $validator = Validator::make(['cron' => $cron, 'timezone' => $timezone], [
            'cron' => ['nullable', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && trim($value) !== '' && !CronExpression::isValidExpression(trim($value))) {
                    $fail('The cron expression is not valid.');
                }
            }],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        return $validator->fails()
            ? ['code' => 'validation_failed', 'message' => (string) $validator->errors()->first(), 'status' => 422]
            : null;
    }

    /**
     * Write the schedule (call problem() first). `$byHand` records "run only by hand" when the cron is empty.
     */
    public function apply(WorkflowProject $project, ?string $cron, ?string $timezone, Authenticatable $user, ?bool $byHand = null): void
    {
        $cron = trim((string) $cron);
        $before = ['cron' => $project->schedule_cron, 'timezone' => $project->schedule_timezone];

        $project->forceFill($cron === ''
            ? ['schedule_cron' => null, 'schedule_timezone' => null, 'scheduled_by_user_id' => null]
            : ['schedule_cron' => $cron, 'schedule_timezone' => $timezone ?: null, 'scheduled_by_user_id' => $user->getAuthIdentifier()]);

        if ($byHand !== null || $cron !== '') {
            $tags = array_values(array_filter((array) ($project->tags ?? []), static fn (mixed $tag): bool => $tag !== self::MANUAL_TAG));
            if ($cron === '' && $byHand === true) {
                $tags[] = self::MANUAL_TAG;
            }
            $project->tags = $tags;
        }
        $project->save();

        $after = ['cron' => $project->schedule_cron, 'timezone' => $project->schedule_timezone];
        if ($before !== $after) {
            $actor = $user->getAuthIdentifier();
            WorkflowAudit::project(WorkflowAudit::SCHEDULE_CHANGED, $project, ['from' => $before, 'to' => $after], 'warning', is_numeric($actor) ? (int) $actor : null);
        }
    }
}
