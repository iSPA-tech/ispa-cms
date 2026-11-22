<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Upgrade;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Upgrades legacy workflow step configurations to the current schema format.
 *
 * Handles backward-compatible transformations such as renaming keys (condition to expression),
 * normalizing legacy variable aliases ($order./$model. to root.), fixing single-equals operators,
 * and converting string booleans to native booleans.
 *
 * @package Cms\Core\Workflows\Upgrade
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StepConfigUpgrader
{
    /**
     * Upgrade legacy step_config into the new schema.
     *
     * @param array<string, mixed> $stepConfig
     * @param  string $stepType
     * @return array{config: array<string,mixed>, _upgrade_log: array<int,array<string,mixed>>}
     */
    public function upgrade(array $stepConfig, string $stepType): array
    {
        $log = [];
        $config = $stepConfig;

        // Step-specific normalization
        if (in_array($stepType, ['condition', 'decision'], true)) {
            if (!isset($config['expression']) && isset($config['condition'])) {
                $config['expression'] = $config['condition'];
                $log[] = [
                    'type' => 'condition_to_expression',
                    'from' => $config['condition'],
                    'to' => $config['expression'],
                ];
            }
            if (isset($config['expression']) && is_string($config['expression'])) {
                [$newExpr, $exprLog] = $this->normalizeExpression($config['expression']);
                if ($newExpr !== $config['expression']) {
                    $log[] = $exprLog;
                    $config['expression'] = $newExpr;
                }
            }
        }

        if (in_array($stepType, ['output', 'output_text'], true)) {
            if (!isset($config['message']) && isset($config['text'])) {
                [$msg, $exprLog] = $this->normalizeAliases($config['text']);
                $config['message'] = $msg;
                $log[] = [
                    'type' => 'output_text_to_message',
                    'from' => $config['text'],
                    'to' => $config['message'],
                ];
                if ($exprLog) {
                    $log[] = $exprLog;
                }
            }
        }

        if ($stepType === 'retrieve') {
            if (isset($config['where']) && $this->isAssociativeArray($config['where'])) {
                $normalized = [];
                foreach ($config['where'] as $field => $value) {
                    $normalized[] = [
                        'field' => $field,
                        'op' => '==',
                        'valueExpr' => is_string($value) ? $value : json_encode($value),
                    ];
                }
                $log[] = [
                    'type' => 'retrieve_where_normalized',
                    'from' => $config['where'],
                    'to' => $normalized,
                ];
                $config['where'] = $normalized;
            }
        }

        // Boolean normalization
        $config = $this->normalizeBooleans($config, [
            'save',
            'first',
            'async',
            'execution.mode',
        ], $log);

        // Generic expression/alias normalization within known keys
        foreach (['expression', 'condition', 'text', 'message'] as $exprKey) {
            if (isset($config[$exprKey]) && is_string($config[$exprKey])) {
                [$newExpr, $exprLog] = $this->normalizeExpression($config[$exprKey]);
                if ($newExpr !== $config[$exprKey]) {
                    $log[] = $exprLog;
                    $config[$exprKey] = $newExpr;
                }
            }
        }

        return [
            'config' => $config,
            '_upgrade_log' => $log,
        ];
    }

    /**
     * Normalize a single expression string.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    protected function normalizeExpression(string $expr): array
    {
        $original = $expr;
        $log = null;

        // Variable alias replacement
        [$expr, $aliasLog] = $this->normalizeAliases($expr);
        if ($aliasLog) {
            $log = $aliasLog;
        }

        // Convert single "=" to "==" when safe
        $converted = preg_replace('/(?<![=!<>])=(?![=])/', '==', $expr);
        if ($converted !== null && $converted !== $expr) {
            $log = [
                'type' => 'expression_fix',
                'from' => $original,
                'to' => $converted,
            ];
            $expr = $converted;
        }

        return [$expr, $log];
    }

    /**
     * Replace legacy $order./$model. aliases with root.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    protected function normalizeAliases(string $expr): array
    {
        $original = $expr;
        $expr = str_replace(['$order.', '$model.'], 'root.', $expr);
        if ($expr !== $original) {
            return [$expr, [
                'type' => 'alias_fix',
                'from' => $original,
                'to' => $expr,
            ]];
        }

        return [$expr, null];
    }

    /**
     * Normalize boolean string values in known keys.
     *
     * @param array<string,mixed> $config
     * @param array<int,string> $paths
     * @return array<string,mixed>
     */
    protected function normalizeBooleans(array $config, array $paths, array &$log): array
    {
        foreach ($paths as $path) {
            if (!Arr::has($config, $path)) {
                continue;
            }

            $value = Arr::get($config, $path);
            if ($value === 'true') {
                Arr::set($config, $path, true);
                $log[] = ['type' => 'bool_fix', 'path' => $path, 'from' => 'true', 'to' => true];
            } elseif ($value === 'false') {
                Arr::set($config, $path, false);
                $log[] = ['type' => 'bool_fix', 'path' => $path, 'from' => 'false', 'to' => false];
            }
        }

        return $config;
    }

    protected function isAssociativeArray(mixed $value): bool
    {
        return is_array($value) && array_values($value) !== $value;
    }
}
