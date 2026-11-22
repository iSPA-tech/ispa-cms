<?php

declare(strict_types=1);

/*
 * Content answer pilot gates (merged as `core.agent_answers`). Off by default.
 *
 * `allowed_projects` is server-owned (compared with `ispa-cms.folder_name`); clients never choose a project.
 * `allowed_providers` lists providers whose adapters have structured-output coverage. Anthropic stays out until
 * its error path stops logging raw exception messages.
 */

return [
    'enabled' => filter_var(env('AGENT_ANSWERS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'allowed_projects' => array_values(array_filter(array_map(
        static fn (string $project): string => trim($project),
        explode(',', (string) env('AGENT_ANSWERS_PROJECTS', '')),
    ))),
    'allowed_providers' => ['openai', 'gemini', 'grok', 'deepseek'],
    'rate_limit_per_minute' => (int) env('AGENT_ANSWERS_RATE_LIMIT_PER_MINUTE', 10),
];
