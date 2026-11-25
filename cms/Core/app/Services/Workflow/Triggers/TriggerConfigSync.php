<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cron\CronExpression;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the project row in step with its trigger start node when a trigger step is saved.
 *
 * The project's schedule columns stay the source of truth for cms:workflow-run-scheduled (and the `PUT schedule`
 * API keeps working); the node is the editor for them:
 * - `trigger_schedule`: writes schedule_cron/schedule_timezone from the node (an empty or invalid cron clears them);
 * - every unattended trigger (schedule, model event, event, webhook, error): the signed-in user who saves it becomes
 *   scheduled_by_user_id, the user those runs act for (checked with schedulerMayRun() at every start).
 * A graph whose start node is another trigger type never runs on the cron (WorkflowProjectRunService::runDue()).
 * Saves without a signed-in user (imports, console) never make anybody the scheduler.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class TriggerConfigSync
{
    public static function stepSaved(WorkflowStep $step): void
    {
        TriggerIndex::forget();

        $type = (string) $step->step_type;
        if (! in_array($type, WorkflowTriggers::UNATTENDED, true) || ! $step->project_id) {
            return;
        }

        $table = (new WorkflowProject())->getTable();
        if (! Schema::hasColumn($table, 'scheduled_by_user_id')) {
            return;
        }

        $changes = [];
        if ($type === WorkflowTriggers::SCHEDULE) {
            $config = is_array($step->step_config) ? $step->step_config : [];
            $cron = trim((string) ($config['cron'] ?? ''));
            $timezone = trim((string) ($config['timezone'] ?? ''));
            $valid = $cron !== '' && CronExpression::isValidExpression($cron)
                && ($timezone === '' || in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true));

            $changes = $valid
                ? ['schedule_cron' => $cron, 'schedule_timezone' => $timezone !== '' ? $timezone : null]
                : ['schedule_cron' => null, 'schedule_timezone' => null];
        }

        $actor = auth()->id();
        if ($actor !== null) {
            $changes['scheduled_by_user_id'] = $actor;
        }

        if ($changes !== []) {
            // A query update: no model events, so the schedule-ownership observer is not re-entered.
            WorkflowProject::query()->whereKey($step->project_id)->update($changes);
        }
    }

    public static function stepDeleted(WorkflowStep $step): void
    {
        TriggerIndex::forget();

        if ($step->step_type === WorkflowTriggers::SCHEDULE && $step->project_id
            && Schema::hasColumn((new WorkflowProject())->getTable(), 'schedule_cron')) {
            WorkflowProject::query()->whereKey($step->project_id)->update(['schedule_cron' => null, 'schedule_timezone' => null]);
        }
    }
}
