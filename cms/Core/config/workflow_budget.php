<?php

/*
|--------------------------------------------------------------------------
| Workflow budget caps
|--------------------------------------------------------------------------
|
| Checked by the workflow executor before every step whose safety class is
| `ai_spend`. A project's own `budget_max_cost_per_run` /
| `budget_max_runs_per_day` win; these defaults apply when the project
| leaves them empty. An empty env value means "no cap" for that limit.
|
| Rule for missing usage: a finished `ai_spend` step that reported no
| `meta.usage` (or no `cost_usd`) counts as unknown spend. When a cost cap
| applies, the next `ai_spend` step stops the run with `budget_exceeded`
| instead of guessing that the call was free.
|
*/

$cap = static function (string $name, string $default): ?string {
    $value = env($name, $default);

    return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
};

return [
    // USD a single run may spend on ai_spend steps.
    'default_max_cost_per_run' => ($v = $cap('WORKFLOW_DEFAULT_MAX_COST_PER_RUN', '1.00')) === null ? null : (float) $v,

    // Estimated USD of one ai_spend call whose step declares no `limits.usd_per_call`, checked against a cost cap.
    'default_usd_per_call' => (float) env('WORKFLOW_DEFAULT_USD_PER_CALL', 0.01),

    // Runs of one project that may reach an ai_spend step per day (app timezone).
    'default_max_runs_per_day' => ($v = $cap('WORKFLOW_DEFAULT_MAX_RUNS_PER_DAY', '100')) === null ? null : (int) $v,

    /*
     * TEMPORARY: step types whose safety class is `ai_spend`, used only until the node registry
     * (lane N0a, Cms\Core\Workflows\Nodes\NodeRegistry) provides each definition's `safety`.
     * The executor asks the registry first and falls back to this list.
     */
    'ai_spend_step_types' => [
        'ai_reasoning',
        'ai_prompt',
        'ai_classify',
        'ai_extract',
        'ai_translate',
        'ai_rewrite',
        'ai_score',
        'ai_summarize',
        'ai_guardrail',
        'ai_web_research',
        'ai_embed',
        'ai_image',
        'jev_review',
        'text_to_speech',
    ],
];
