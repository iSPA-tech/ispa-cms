<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Presets;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ready-made designer choices (`core.workflow_presets`): JEV criteria sets, score rubrics and classify label sets.
 *
 * Core sets live in `core.workflow_presets.sets`; a module adds its own `sets` from its config file and registers the
 * config key with registerModuleConfig() from its provider's `app()->booted()` (as module node services register with
 * NodeRegistry::registerModuleService()). A module set never replaces a Core set of the same key.
 *
 * Output per set: `{key, label, description, module, items}` (+ `source` for sets read from site records), text in the
 * user's language (translation key, else the English in config).
 *
 * @package Cms\Core\Services\Workflow\Presets
 * @since   2026-11-24
 */
class WorkflowPresetCatalog
{
    /**
     * Add a module's preset sets: `$configKey` holds `['sets' => [...]]` in the Core shape.
     */
    public static function registerModuleConfig(string $module, string $configKey): void
    {
        config(['core.workflow_presets.module_configs.' . $module => $configKey]);
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_values(array_filter((array) config('core.workflow_presets.kinds', []), 'is_string'));
    }

    /**
     * Every set of one kind, Core first, then modules in registration order.
     *
     * @return list<array<string, mixed>>
     */
    public function forKind(string $kind): array
    {
        $out = [];
        foreach ($this->rows() as $key => [$module, $row]) {
            if (($row['kind'] ?? null) !== $kind) {
                continue;
            }

            $set = $this->present($key, $module, $row);
            if ($set !== null) {
                $out[] = $set;
            }
        }

        return $out;
    }

    /**
     * One set by key (any kind), or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $row = $this->rows()[$key] ?? null;

        return $row === null ? null : $this->present($key, $row[0], $row[1]);
    }

    /**
     * Raw rows by set key: [module|null, row].
     *
     * @return array<string, array{0: string|null, 1: array<string, mixed>}>
     */
    protected function rows(): array
    {
        $rows = [];
        foreach ((array) config('core.workflow_presets.sets', []) as $key => $row) {
            if (is_string($key) && is_array($row)) {
                $rows[$key] = [null, $row];
            }
        }

        foreach ((array) config('core.workflow_presets.module_configs', []) as $module => $configKey) {
            if (!is_string($module) || !is_string($configKey)) {
                continue;
            }
            foreach ((array) config($configKey . '.sets', []) as $key => $row) {
                if (is_string($key) && is_array($row) && !isset($rows[$key])) {
                    $rows[$key] = [$module, $row];
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null Null when a source set cannot be read (logged; the other sets still list).
     */
    protected function present(string $key, ?string $module, array $row): ?array
    {
        $base = "text.workflow.preset.{$key}";
        $set = [
            'key' => $key,
            'label' => $this->text($row['label_key'] ?? "{$base}.title", $row['label'] ?? $key),
            'description' => $this->text($row['description_key'] ?? "{$base}.desc", $row['description'] ?? null),
            'module' => $module,
        ];

        if (is_string($row['source'] ?? null)) {
            $set['source'] = $row['source'];
            try {
                $resolver = app((string) ($row['resolver'] ?? ''));
                if (!$resolver instanceof WorkflowPresetSource) {
                    throw new \InvalidArgumentException("Preset {$key}: resolver is not a WorkflowPresetSource.");
                }
                $set['items'] = array_values($resolver->items());
            } catch (Throwable $e) {
                Log::warning('Workflow preset source skipped: ' . $e->getMessage());

                return null;
            }

            return $set;
        }

        $set['items'] = [];
        foreach ((array) ($row['items'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['key'] ?? null)) {
                continue;
            }
            $itemKey = $item['key'];
            $out = [
                'key' => $itemKey,
                'label' => $this->text($item['label_key'] ?? "{$base}.{$itemKey}", $item['label'] ?? $itemKey),
            ];
            if (array_key_exists('text', $item)) {
                $out['text'] = $this->text($item['text_key'] ?? "{$base}.{$itemKey}_text", $item['text']);
            }
            if (($row['kind'] ?? null) === 'jev_criteria') {
                $out['default'] = (bool) ($item['default'] ?? true);
            }
            if (array_key_exists('weight', $item)) {
                $out['weight'] = is_numeric($item['weight']) ? $item['weight'] + 0 : 1;
            }
            $set['items'][] = $out;
        }

        return $set;
    }

    private function text(string $key, mixed $fallback): ?string
    {
        $text = __($key);
        if (is_string($text) && $text !== $key) {
            return $text;
        }

        return is_string($fallback) ? $fallback : null;
    }
}
