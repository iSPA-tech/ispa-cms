<?php

/*
|--------------------------------------------------------------------------
| Workflow project schedules
|--------------------------------------------------------------------------
|
| `cms:workflow-run-scheduled` starts authored workflow projects whose
| `schedule_cron` is due. The scheduler entry in app/Console/Kernel.php is
| registered only when `enabled` is true, so a site opts in explicitly.
|
| Each pass also fails runs stuck in `running` (their worker died) with no
| activity for `stale_running_minutes`, as `stale_running`, so they stop
| blocking new runs. Keep it above the instance job timeout.
|
*/

return [
    'enabled' => (bool) env('WORKFLOW_SCHEDULER_ENABLED', false),

    'stale_running_minutes' => max(1, (int) env('WORKFLOW_STALE_RUNNING_MINUTES', 30)),

    // A cron minute a late or overlapped pass missed is still started up to this many minutes after it was due.
    'catch_up_minutes' => max(0, (int) env('WORKFLOW_SCHEDULE_CATCH_UP_MINUTES', 10)),

    // A triggered start (model event, app event, error workflow) that finds a run of the project in progress is
    // queued again after `busy_retry_seconds`, at most `busy_retry_attempts` times (default: about an hour).
    'busy_retry_seconds' => max(1, (int) env('WORKFLOW_BUSY_RETRY_SECONDS', 30)),
    'busy_retry_attempts' => max(0, (int) env('WORKFLOW_BUSY_RETRY_ATTEMPTS', 120)),
];
