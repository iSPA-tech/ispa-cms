<?php

/*
 * Workflow guardrails (roadmap phase B, B7): per-project circuit breakers and the global pause.
 *
 * A breaker that trips switches the project off (`is_active = false`, `paused_reason`, `paused_at`), writes an audit
 * entry and alerts the publishers once; a person turns it back on. A limit of 0 (or less) turns that breaker off.
 * Read by Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker and WorkflowGlobalPause.
 */
return [
    'breaker' => [
        // Runs started in the last hour (any trigger).
        'runs_per_hour' => (int) env('WORKFLOW_BREAKER_RUNS_PER_HOUR', 60),
        // Steps that publish or call an outside service (node safety `publish` / `external_call`) in the last hour.
        'actions_per_hour' => (int) env('WORKFLOW_BREAKER_ACTIONS_PER_HOUR', 30),
        // The same steps within one run; the run fails before the step over the cap.
        'actions_per_run' => (int) env('WORKFLOW_BREAKER_ACTIONS_PER_RUN', 30),
        // USD spent by runs started in the last hour.
        'cost_per_hour' => (float) env('WORKFLOW_BREAKER_COST_PER_HOUR', 5.00),
        // Share of failed runs among the last `error_window` finished runs (0.5 = 50 %); needs a full window.
        'error_rate' => (float) env('WORKFLOW_BREAKER_ERROR_RATE', 0.5),
        'error_window' => (int) env('WORKFLOW_BREAKER_ERROR_WINDOW', 10),
    ],

    // Server-side switch: true pauses every workflow whatever the Webmaster's setting says (incident response).
    'paused_all' => (bool) env('WORKFLOW_PAUSED_ALL', false),

    // Seconds between checks of a run held by the global pause (it goes back to `waiting` and is queued again).
    'paused_recheck_seconds' => (int) env('WORKFLOW_PAUSED_RECHECK_SECONDS', 60),
];
