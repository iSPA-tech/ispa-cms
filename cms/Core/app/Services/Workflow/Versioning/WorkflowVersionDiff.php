<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Versioning;

/**
 * Difference between two canonical workflow graphs ($from → $to).
 *
 * Steps are matched by step_code; a changed step lists the changed fields and the changed config KEYS only (never
 * config values: prompts may hold personal data). Connections are matched by from/to code and edge handle. Run
 * settings list before/after values (schedules, budgets, ids — no secrets).
 *
 * @package Cms\Core\Services\Workflow\Versioning
 * @since   2026-11-24
 */
final class WorkflowVersionDiff
{
    private const STEP_FIELDS = ['name', 'step_type', 'is_start', 'position_x', 'position_y', 'input_schema', 'output_schema'];

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @return array{steps: array{added: list<array>, removed: list<array>, changed: list<array>}, connections: array{added: list<array>, removed: list<array>}, settings: list<array>, identical: bool}
     */
    public static function between(array $from, array $to): array
    {
        $before = self::stepsByCode($from);
        $after = self::stepsByCode($to);

        $added = [];
        $removed = [];
        $changed = [];
        foreach ($after as $code => $step) {
            if (!isset($before[$code])) {
                $added[] = ['step_code' => $code, 'step_type' => $step['step_type'] ?? null, 'name' => $step['name'] ?? null];
                continue;
            }
            $fields = [];
            foreach (self::STEP_FIELDS as $field) {
                if (($before[$code][$field] ?? null) !== ($step[$field] ?? null)) {
                    $fields[] = $field;
                }
            }
            $keys = self::changedKeys(
                is_array($before[$code]['step_config'] ?? null) ? $before[$code]['step_config'] : [],
                is_array($step['step_config'] ?? null) ? $step['step_config'] : [],
            );
            if ($fields !== [] || $keys !== []) {
                $changed[] = ['step_code' => $code, 'step_type' => $step['step_type'] ?? null, 'fields' => $fields, 'config_keys' => $keys];
            }
        }
        foreach ($before as $code => $step) {
            if (!isset($after[$code])) {
                $removed[] = ['step_code' => $code, 'step_type' => $step['step_type'] ?? null, 'name' => $step['name'] ?? null];
            }
        }

        $edgesBefore = self::edges($from);
        $edgesAfter = self::edges($to);

        $settings = [];
        $settingsBefore = is_array($from['settings'] ?? null) ? $from['settings'] : [];
        $settingsAfter = is_array($to['settings'] ?? null) ? $to['settings'] : [];
        foreach (array_unique(array_merge(array_keys($settingsBefore), array_keys($settingsAfter))) as $key) {
            if (($settingsBefore[$key] ?? null) !== ($settingsAfter[$key] ?? null)) {
                $settings[] = ['key' => $key, 'before' => $settingsBefore[$key] ?? null, 'after' => $settingsAfter[$key] ?? null];
            }
        }

        $result = [
            'steps' => ['added' => $added, 'removed' => $removed, 'changed' => $changed],
            'connections' => [
                'added' => array_values(array_diff_key($edgesAfter, $edgesBefore)),
                'removed' => array_values(array_diff_key($edgesBefore, $edgesAfter)),
            ],
            'settings' => $settings,
        ];
        $result['identical'] = $added === [] && $removed === [] && $changed === [] && $settings === []
            && $result['connections']['added'] === [] && $result['connections']['removed'] === [];

        return $result;
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, array<string, mixed>>
     */
    private static function stepsByCode(array $graph): array
    {
        $steps = [];
        foreach ((array) ($graph['steps'] ?? []) as $step) {
            if (is_array($step) && is_scalar($step['step_code'] ?? null)) {
                $steps[(string) $step['step_code']] = $step;
            }
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $graph
     * @return array<string, array{from: ?string, to: ?string, handle: ?string}>
     */
    private static function edges(array $graph): array
    {
        $edges = [];
        foreach ((array) ($graph['connections'] ?? []) as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $condition = is_array($edge['condition'] ?? null) ? $edge['condition'] : [];
            $handle = $condition['source_handle'] ?? $condition['condition_value'] ?? $condition['condition'] ?? null;
            $row = ['from' => $edge['from'] ?? null, 'to' => $edge['to'] ?? null, 'handle' => is_scalar($handle) ? (string) $handle : null];
            $edges[$row['from'] . '|' . $row['to'] . '|' . $row['handle'] . '|' . json_encode($condition)] = $row;
        }

        return $edges;
    }

    /**
     * Top-level config keys whose value differs (added, removed or changed).
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private static function changedKeys(array $before, array $after): array
    {
        $keys = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after) || $before[$key] !== $after[$key]) {
                $keys[] = (string) $key;
            }
        }
        sort($keys);

        return $keys;
    }
}
