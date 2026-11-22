<?php

declare(strict_types=1);

namespace Cms\Core\Services\Ai;

use Nwidart\Modules\Facades\Module;
use Throwable;

/**
 * Registry of operator-facing AI task labels.
 *
 * Every enabled module declares `ai_runtime_tasks` (task_key => label) in its own
 * config file (config key: lowercase module name). Core and app-level tasks live in
 * `core.ai_usage.ai_runtime_tasks` and are attributed to the Core module.
 */
final class AiTaskCatalog
{
    /**
     * Collect task labels and owning modules from Core and every enabled module.
     *
     * @return array<string, array{label: string, module: string}>
     */
    public function all(): array
    {
        $catalog = [];

        try {
            foreach (Module::allEnabled() as $module) {
                $name = (string) $module->getName();
                $this->collect($catalog, (array) config(strtolower($name) . '.ai_runtime_tasks', []), $name);
            }
        } catch (Throwable) {
            // Modules package unavailable: fall back to the Core labels only.
        }

        $this->collect($catalog, (array) config('core.ai_usage.ai_runtime_tasks', []), 'Core');

        return $catalog;
    }

    /**
     * Whether a task's prompt is private: its per-call log keeps no prompt hash or other prompt-derived value.
     *
     * Declared per module as `<module>.ai_runtime_private_prompt_tasks` (a list of task keys, enabled modules only)
     * and for Core and app tasks as `core.ai_usage.ai_runtime_private_prompt_tasks`.
     */
    public function isPromptPrivate(string $task): bool
    {
        $task = strtolower(trim($task));
        if ($task === '') {
            return false;
        }

        $lists = [(array) config('core.ai_usage.ai_runtime_private_prompt_tasks', [])];
        try {
            foreach (Module::allEnabled() as $module) {
                $lists[] = (array) config(strtolower((string) $module->getName()) . '.ai_runtime_private_prompt_tasks', []);
            }
        } catch (Throwable) {
            // Modules package unavailable: only the Core list applies.
        }

        foreach ($lists as $list) {
            foreach ($list as $key) {
                if (is_string($key) && strtolower(trim($key)) === $task) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, array{label: string, module: string}> $catalog
     * @param array<mixed> $labels
     */
    private function collect(array &$catalog, array $labels, string $module): void
    {
        foreach ($labels as $task => $label) {
            $key = strtolower(trim((string) $task));
            if ($key !== '' && is_string($label) && trim($label) !== '' && ! isset($catalog[$key])) {
                $catalog[$key] = ['label' => trim($label), 'module' => $module];
            }
        }
    }
}
