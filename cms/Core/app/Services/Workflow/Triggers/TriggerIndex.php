<?php

namespace Cms\Core\Services\Workflow\Triggers;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Which active projects start on which model class or app event.
 *
 * Built from the start node of every active project whose start node is `trigger_model_event` or `trigger_event`,
 * cached for `index_cache_seconds` and cleared whenever a workflow step or project is saved or deleted through the
 * model (the cache TTL covers query-builder deletes). Model listeners consult it on every Eloquent event, so a class
 * with no entry costs one array lookup.
 *
 * @package Cms\Core\Services\Workflow\Triggers
 * @since   2026.11 (2026-11-24)
 */
class TriggerIndex
{
    public const CACHE_KEY = 'core:workflow:trigger-index:v1';

    /** @var array{model: array<string, list<array<string, mixed>>>, event: array<string, list<array<string, mixed>>>}|null */
    private static ?array $memo = null;

    /** Unix time the per-process copy was read; it is re-read from the cache after MEMO_SECONDS (long-lived workers). */
    private static int $memoAt = 0;

    private const MEMO_SECONDS = 30;

    private static bool $building = false;

    /**
     * Drop the per-process copy only (a new application instance, e.g. between tests).
     */
    /**
     * Cache store shared by every host for trigger state (index, debounce tokens, webhook nonces):
     * `core.workflow.trigger_cache_store`, else the app default. A per-host file cache would let a debounce token
     * written on the web host go unseen by the worker on another host, so the trigger never fires (review finding 36).
     */
    public static function cache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('core.workflow.trigger_cache_store');

        return is_string($store) && $store !== '' && is_array(config('cache.stores.' . $store))
            ? Cache::store($store)
            : Cache::store();
    }

    public static function flushMemo(): void
    {
        self::$memo = null;
    }

    public static function forget(): void
    {
        self::$memo = null;
        try {
            self::cache()->forget(self::CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    /**
     * @return list<array{project_id: int, step_id: int, config: array<string, mixed>}>
     */
    public static function forModel(string $class): array
    {
        return self::index()['model'][ltrim($class, '\\')] ?? [];
    }

    /**
     * @return list<array{project_id: int, step_id: int, config: array<string, mixed>}>
     */
    public static function forEvent(string $event): array
    {
        return self::index()['event'][$event] ?? [];
    }

    /**
     * @return array{model: array<string, list<array<string, mixed>>>, event: array<string, list<array<string, mixed>>>}
     */
    public static function index(): array
    {
        if (self::$memo !== null && time() - self::$memoAt < self::MEMO_SECONDS) {
            return self::$memo;
        }

        if (self::$building) {
            // Model events raised while the index loads (retrieved rows) must not recurse.
            return ['model' => [], 'event' => []];
        }

        self::$building = true;
        try {
            $seconds = max(1, (int) WorkflowTriggers::setting('index_cache_seconds', 300));

            self::$memoAt = time();

            return self::$memo = self::cache()->remember(self::CACHE_KEY, $seconds, static fn () => self::build());
        } catch (\Throwable $e) {
            Log::warning('Workflow trigger index not built', ['error' => $e->getMessage()]);

            return ['model' => [], 'event' => []];
        } finally {
            self::$building = false;
        }
    }

    /**
     * @return array{model: array<string, list<array<string, mixed>>>, event: array<string, list<array<string, mixed>>>}
     */
    protected static function build(): array
    {
        $index = ['model' => [], 'event' => []];
        $projectTable = (new WorkflowProject())->getTable();
        $stepTable = (new WorkflowStep())->getTable();
        if (! Schema::hasTable($projectTable) || ! Schema::hasTable($stepTable)) {
            return $index;
        }

        // Trigger nodes of the PUBLISHED versions of active projects: a draft edit never changes what fires a run.
        if (! Schema::hasColumn($projectTable, 'published_version_id')) {
            return $index;
        }

        $projects = WorkflowProject::query()
            ->where('is_active', true)
            ->whereNotNull('published_version_id')
            ->orderBy('id')
            ->get(['id', 'published_version_id']);

        foreach ($projects as $project) {
            // One start node per project: its first trigger step.
            $step = WorkflowTriggers::publishedStartStep($project);
            if (! $step) {
                continue;
            }
            $config = is_array($step->step_config) ? $step->step_config : [];
            $entry = ['project_id' => (int) $project->id, 'step_id' => (int) $step->id, 'config' => $config];

            if ($step->step_type === WorkflowTriggers::MODEL_EVENT && is_string($config['model'] ?? null) && $config['model'] !== '') {
                $index['model'][ltrim($config['model'], '\\')][] = $entry;
            } elseif ($step->step_type === WorkflowTriggers::EVENT && is_string($config['event'] ?? null) && $config['event'] !== '') {
                $index['event'][$config['event']][] = $entry;
            }
        }

        return $index;
    }
}
